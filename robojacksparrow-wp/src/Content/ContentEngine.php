<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content;

use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Content\Dto\Faq;
use RoboJackSparrow\Content\Dto\GeneratedContent;
use RoboJackSparrow\Content\Draft\ArticleDraftParser;
use RoboJackSparrow\Content\Prompts\PromptLibrary;
use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Content\Tone\TonePresets;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Research\Dto\ResearchData;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;

/**
 * Gera o artigo completo (titulo, secoes com HTML, FAQ, palavras-chave,
 * prompt de imagem) em UMA UNICA chamada de LLM, em vez de uma chamada de
 * outline + uma por secao + uma de FAQ: um prompt bem estruturado (ver
 * PromptLibrary::article_generation) e um modelo moderno dao conta de um
 * artigo inteiro de ~1500-2500 palavras em uma resposta so, o que reduz
 * latencia e custo de tokens por artigo sem perder qualidade percebida.
 *
 * O briefing factual do Research Engine (Tavily) e injetado no mesmo prompt
 * para ancorar o conteudo gerado em fatos verificados, e a instrucao de
 * originalidade e explicita: a fonte raspada e so referencia factual, o
 * texto final tem que ser uma redacao propria, nunca uma copia/parafrase
 * proxima da fonte.
 */
class ContentEngine
{
    public function __construct(
        private LLMRouter $llm,
        private SeoGenerator $seo,
        private ArticleDraftParser $draftParser,
        private PromptLibrary $prompts,
        private SettingRepository $settings,
        private Logger $logger
    ) {
    }

    /**
     * @param ?string $preferredProvider Article-level override (e.g. the
     *     'assigned_llm' column, when not 'auto'). Wins over the global
     *     'rjs_preferred_llm_provider' setting; both are only a hint to the
     *     LLMRouter, which still fails over to another provider if needed.
     * @param ?string $modelOverride Article-level model override (e.g. the
     *     'assigned_llm_model' column). Wins over the provider's own
     *     admin-configured default model (rjs_openai_model, etc.).
     * @param ?string $toneOverride Article-level tone preset key (e.g. the
     *     'assigned_tone' column). Wins over the global 'rjs_content_tone'
     *     setting. See TonePresets for the available keys.
     */
    public function generate(
        int $articleId,
        ScrapedContent $source,
        ResearchData $research,
        ?string $preferredProvider = null,
        ?string $modelOverride = null,
        ?string $toneOverride = null
    ): GeneratedContent {
        $briefing = $research->toBriefing();
        $provider = $this->resolvePreferredProvider($preferredProvider);
        $model = $modelOverride !== null && trim($modelOverride) !== '' ? $modelOverride : null;
        $wordCount = (int) $this->settings->get('rjs_target_word_count', 1500);

        $this->logger->info('Starting single-call article generation', ['article_id' => $articleId]);

        $response = $this->llm->route(new LLMRequest(
            prompt: $this->prompts->get('article_generation', [
                'title'             => $source->getTitle(),
                'content'           => mb_substr($source->getText(), 0, 8000),
                'research_briefing' => $this->briefingOrFallback($briefing),
                'language'          => $this->settings->get('rjs_content_language', 'pt_BR'),
                'tone_instructions' => $this->resolveToneInstructions($toneOverride),
                'word_count'        => $wordCount,
            ]),
            model: $model,
            isJsonMode: true,
            maxTokens: $this->resolveMaxTokens($wordCount),
            temperature: 0.7,
            preferredProvider: $provider
        ));

        $draft = $this->draftParser->parse($response->getContent());
        $totalTokens = $response->getTokensUsed();

        $sections = [];
        foreach ($draft->getSections() as $section) {
            $sections[] = [
                'title'      => $section['title'],
                'level'      => $section['level'],
                'html'       => $this->formatSectionHtml($section, $section['html']),
                'word_count' => str_word_count(strip_tags($section['html'])),
            ];
        }

        $fullContent = implode("\n\n", array_column($sections, 'html'));
        $seoData = $this->seo->generate($draft->getTitle(), $draft->getMetaDescription(), $fullContent);

        return new GeneratedContent(
            title: $draft->getTitle(),
            htmlContent: $this->appendSourcesBlock($this->assembleHtml($sections, $draft->getFaqs()), $research),
            seoTitle: $seoData->getTitle(),
            seoDescription: $seoData->getDescription(),
            schemaArticle: $seoData->getArticleSchema(),
            schemaFaq: Faq::schemaFor($draft->getFaqs()),
            focusKeywords: $draft->getFocusKeywords(),
            faqs: $draft->getFaqs(),
            sections: $sections,
            imagePrompt: $draft->getImagePrompt(),
            tokensUsed: $totalTokens
        );
    }

    /**
     * @param array{title: string, level: int, html: string} $section
     */
    private function formatSectionHtml(array $section, string $body): string
    {
        $level = max(2, min(6, (int) $section['level']));
        $heading = sprintf('<h%1$d>%2$s</h%1$d>', $level, esc_html($section['title']));

        return $heading . "\n" . trim($body);
    }

    /**
     * @param array<int, array{title: string, level: int, html: string, word_count: int}> $sections
     * @param Faq[] $faqs
     */
    private function assembleHtml(array $sections, array $faqs): string
    {
        $html = implode("\n\n", array_column($sections, 'html'));

        if ($faqs !== []) {
            $html .= "\n\n<h2>Perguntas Frequentes</h2>\n";
            foreach ($faqs as $faq) {
                $html .= sprintf(
                    "<h3>%s</h3>\n<p>%s</p>\n",
                    esc_html($faq->getQuestion()),
                    esc_html($faq->getAnswer())
                );
            }
        }

        return $html;
    }

    /**
     * External citations, linked to the verified research sources (Tavily,
     * Fase 3). Answer engines (AEO/GEO) weigh cited, checkable sources when
     * deciding whether to surface/quote a page, so this is skipped only
     * when there is genuinely nothing to cite (fallback research).
     */
    private function appendSourcesBlock(string $html, ResearchData $research): string
    {
        $items = '';

        foreach ($research->getSources() as $source) {
            $url = trim($source->getUrl());
            if ($url === '') {
                continue;
            }

            $label = trim($source->getTitle()) !== '' ? $source->getTitle() : $url;
            $items .= sprintf(
                "<li><a href=\"%s\" target=\"_blank\" rel=\"noopener noreferrer\">%s</a></li>\n",
                esc_url($url),
                esc_html($label)
            );
        }

        if ($items === '') {
            return $html;
        }

        return $html . "\n\n<h2>Fontes</h2>\n<ul>\n{$items}</ul>\n";
    }

    private function briefingOrFallback(string $briefing): string
    {
        return $briefing !== '' ? $briefing : 'Nenhuma pesquisa adicional disponivel; use apenas o conteudo fonte.';
    }

    private function resolvePreferredProvider(?string $override): ?string
    {
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        $global = trim((string) $this->settings->get('rjs_preferred_llm_provider', ''));

        return $global !== '' ? $global : null;
    }

    private function resolveToneInstructions(?string $override): string
    {
        if ($override !== null && TonePresets::isValid($override)) {
            return TonePresets::instructionsFor($override);
        }

        $global = (string) $this->settings->get('rjs_content_tone', TonePresets::defaultPreset());

        return TonePresets::instructionsFor($global);
    }

    /**
     * Approximate output token ceiling for the single consolidated call:
     * ~2.2 tokens/word covers the article body itself plus the JSON
     * structure/FAQ/heading overhead, with a floor for very short targets
     * and a cap so a misconfigured word count can't request an unbounded
     * (and unboundedly expensive) response.
     */
    private function resolveMaxTokens(int $wordCount): int
    {
        return min(16000, max(4096, (int) round($wordCount * 2.2)));
    }
}
