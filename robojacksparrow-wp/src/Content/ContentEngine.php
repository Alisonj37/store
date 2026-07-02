<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content;

use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Ai\Memory\MemoryBuffer;
use RoboJackSparrow\Content\Dto\GeneratedContent;
use RoboJackSparrow\Content\Dto\Outline;
use RoboJackSparrow\Content\Faq\FaqExtractor;
use RoboJackSparrow\Content\Outline\OutlineGenerator;
use RoboJackSparrow\Content\Prompts\PromptLibrary;
use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Research\Dto\ResearchData;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;

/**
 * Pipeline: outline -> sections (com memoria) -> FAQ -> SEO.
 *
 * O briefing factual do Research Engine (Tavily, Fase 3) e injetado como
 * contexto em CADA prompt de geracao (outline e secoes), para ancorar o
 * conteudo gerado em fatos verificados em vez de depender so da fonte
 * raspada/RSS.
 */
class ContentEngine
{
    public function __construct(
        private LLMRouter $llm,
        private MemoryBuffer $memory,
        private SeoGenerator $seo,
        private FaqExtractor $faq,
        private OutlineGenerator $outlineGenerator,
        private PromptLibrary $prompts,
        private Logger $logger
    ) {
    }

    public function generate(int $articleId, ScrapedContent $source, ResearchData $research): GeneratedContent
    {
        $contextKey = "article_{$articleId}";
        $this->memory->clear($contextKey);

        $briefing = $research->toBriefing();

        // === ETAPA 1: OUTLINE ===
        $this->logger->info('Starting outline generation', ['article_id' => $articleId]);

        $outlineResponse = $this->llm->route(new LLMRequest(
            prompt: $this->prompts->get('outline_generation', [
                'title'             => $source->getTitle(),
                'content'           => mb_substr($source->getText(), 0, 8000),
                'research_briefing' => $this->briefingOrFallback($briefing),
                'language'          => get_option('rjs_content_language', 'pt_BR'),
                'tone'              => get_option('rjs_content_tone', 'professional'),
                'word_count'        => get_option('rjs_target_word_count', 1500),
            ]),
            isJsonMode: true,
            maxTokens: 2048,
            temperature: 0.7
        ));

        $outline = $this->outlineGenerator->parse($outlineResponse->getContent());
        $totalTokens = $outlineResponse->getTokensUsed();

        // === ETAPA 2: CONTEUDO POR SECAO (com memoria) ===
        $this->logger->info('Starting content generation', [
            'article_id' => $articleId,
            'sections'   => count($outline->getSections()),
        ]);

        $sections = [];
        foreach ($outline->getSections() as $index => $section) {
            $generated = $this->generateSection($contextKey, $source, $outline, $section, $index, $briefing);
            $sections[] = $generated;
            $totalTokens += $generated['tokens'];
        }

        $fullContent = implode("\n\n", array_column($sections, 'html'));

        // === ETAPA 3: FAQ ===
        $this->logger->info('Starting FAQ extraction', ['article_id' => $articleId]);
        $faqs = $this->faq->extract($fullContent);

        // === ETAPA 4: SEO ===
        $this->logger->info('Generating SEO data', ['article_id' => $articleId]);
        $seoData = $this->seo->generate($outline, $fullContent);

        return new GeneratedContent(
            title: $outline->getTitle(),
            htmlContent: $this->assembleHtml($sections, $faqs),
            seoTitle: $seoData->getTitle(),
            seoDescription: $seoData->getDescription(),
            schemaArticle: $seoData->getArticleSchema(),
            schemaFaq: $this->faq->toSchema($faqs),
            focusKeywords: $outline->getFocusKeywords(),
            faqs: $faqs,
            sections: $sections,
            imagePrompt: $outline->getImagePrompt(),
            tokensUsed: $totalTokens
        );
    }

    /**
     * @param array{title: string, level: int} $section
     * @return array{title: string, level: int, html: string, word_count: int, tokens: int}
     */
    private function generateSection(
        string $contextKey,
        ScrapedContent $source,
        Outline $outline,
        array $section,
        int $index,
        string $briefing
    ): array {
        $memory = $this->memory->getContext($contextKey);

        $response = $this->llm->route(new LLMRequest(
            prompt: $this->prompts->get('section_generation', [
                'section_title'     => $section['title'],
                'section_level'     => $section['level'],
                'section_index'     => $index + 1,
                'total_sections'    => count($outline->getSections()),
                'outline_context'   => $outline->toString(),
                'source_content'    => $source->getText(),
                'research_briefing' => $this->briefingOrFallback($briefing),
            ]),
            contextMemory: $memory,
            maxTokens: 2048,
            temperature: 0.7
        ));

        $this->memory->push($contextKey, 'assistant', $response->getContent(), $response->getTokensUsed());

        return [
            'title'      => $section['title'],
            'level'      => $section['level'],
            'html'       => $this->formatSectionHtml($section, $response->getContent()),
            'word_count' => str_word_count(strip_tags($response->getContent())),
            'tokens'     => $response->getTokensUsed(),
        ];
    }

    /**
     * @param array{title: string, level: int} $section
     */
    private function formatSectionHtml(array $section, string $body): string
    {
        $level = max(2, min(6, (int) $section['level']));
        $heading = sprintf('<h%1$d>%2$s</h%1$d>', $level, esc_html($section['title']));

        return $heading . "\n" . trim($body);
    }

    /**
     * @param array<int, array{title: string, level: int, html: string, word_count: int, tokens: int}> $sections
     * @param \RoboJackSparrow\Content\Dto\Faq[] $faqs
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

    private function briefingOrFallback(string $briefing): string
    {
        return $briefing !== '' ? $briefing : 'Nenhuma pesquisa adicional disponivel; use apenas o conteudo fonte.';
    }
}
