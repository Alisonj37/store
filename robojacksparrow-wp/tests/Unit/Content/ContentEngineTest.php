<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Ai\LLMException;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Content\ContentEngine;
use RoboJackSparrow\Content\Draft\ArticleDraftParser;
use RoboJackSparrow\Content\Prompts\PromptLibrary;
use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Research\Dto\ResearchData;
use RoboJackSparrow\Research\Dto\ResearchSource;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Tests\TestCase;

final class ContentEngineTest extends TestCase
{
    private function makeEngine(ScriptedProvider $provider, ?SettingRepository $settings = null): ContentEngine
    {
        $llm = new LLMRouter(['test' => $provider], new HealthMonitor(), new Logger());

        return new ContentEngine(
            $llm,
            new SeoGenerator(),
            new ArticleDraftParser(),
            new PromptLibrary(),
            $settings ?? new SettingRepository(new Encryption(), new Logger()),
            new Logger()
        );
    }

    public function testSingleCallProducesTitleSectionsFaqAndSeo(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(
            url: 'https://example.com/original-article',
            title: 'Noticia Original Sobre Robos',
            text: 'Um robo chamado Jack Sparrow foi anunciado hoje.',
            html: null
        );

        $research = new ResearchData(
            facts: ['Fato verificado XYZ sobre o robo.'],
            sources: [new ResearchSource('https://example.com/fonte', 'Fonte Exemplo Verificada', 'conteudo', 0.9)],
            answer: 'Resposta verificada ABC sobre o lancamento do robo.',
            entities: ['Jack Sparrow'],
            fromFallback: false
        );

        $result = $engine->generate(42, $source, $research);

        $this->assertSame('Titulo Gerado de Teste', $result->getTitle());
        $this->assertCount(2, $result->getSections());
        $this->assertStringContainsString('<h2>Introducao</h2>', $result->getHtmlContent());
        $this->assertCount(2, $result->getFaqs());
        $this->assertStringContainsString('<h2>Perguntas Frequentes</h2>', $result->getHtmlContent());
        $this->assertSame('FAQPage', $result->getSchemaFaq()['@type']);
        $this->assertSame(['palavra-chave-1', 'palavra-chave-2'], $result->getFocusKeywords());
        $this->assertSame(500, $result->getTokensUsed(), 'a single LLM call now produces the whole article');

        // Exactly one LLM call for the whole article - the whole point of
        // the single-call rewrite.
        $this->assertCount(1, $provider->prompts);

        // Proves the Tavily briefing is actually injected into the prompt.
        $this->assertStringContainsString('Resposta verificada ABC sobre o lancamento do robo.', $provider->prompts[0]);

        // The citation links to the actual original source, not the
        // Tavily research results (which are only topically related and
        // previously showed up as a pile of unrelated "Fontes").
        $this->assertStringContainsString('<h2>Fonte</h2>', $result->getHtmlContent());
        $this->assertStringContainsString('<a href="https://example.com/original-article" target="_blank" rel="noopener noreferrer">Noticia Original Sobre Robos</a>', $result->getHtmlContent());
        $this->assertStringNotContainsString('Fonte Exemplo Verificada', $result->getHtmlContent(), 'Tavily research results must not be rendered as article citations');
    }

    public function testModelOverrideReachesTheLlmRequest(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'Algum texto fonte.', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research, null, 'gpt-4o-mini');

        $this->assertSame(['gpt-4o-mini'], $provider->requestedModels);
    }

    public function testBlankModelOverrideLeavesModelResolutionToTheProvider(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'Algum texto fonte.', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research, null, '   ');

        $this->assertSame([null], $provider->requestedModels, 'a blank override must not be forwarded as a literal model string');
    }

    public function testToneOverrideChangesThePromptInstructions(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research, null, null, 'afiliado');

        $this->assertStringContainsString('persuasivo', $provider->prompts[0]);
        $this->assertStringContainsString('chamada para acao', $provider->prompts[0]);
    }

    public function testGlobalToneSettingIsUsedWhenNoOverrideGiven(): void
    {
        $provider = new ScriptedProvider();
        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_content_tone', 'noticia');
        $engine = $this->makeEngine($provider, $settings);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research);

        $this->assertStringContainsString('jornalistico', $provider->prompts[0]);
    }

    public function testUnknownToneOverrideFallsBackToGlobalDefault(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research, null, null, 'not-a-real-preset');

        $this->assertStringContainsString('equilibrado', $provider->prompts[0]);
    }

    public function testPromptInstructsOriginalityNotCopying(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $engine->generate(1, $source, $research);

        $this->assertStringContainsString('ORIGINALIDADE', $provider->prompts[0]);
        $this->assertStringContainsString('nunca copie', mb_strtolower($provider->prompts[0]));
    }

    public function testNoSourceBlockIsAddedWhenTheOriginalSourceHasNoUrl(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: '', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $result = $engine->generate(1, $source, $research);

        $this->assertStringNotContainsString('<h2>Fonte</h2>', $result->getHtmlContent());
    }

    public function testFallbackResearchDataStillProducesContentWithPlaceholderBriefing(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'Some source text.', html: null);
        $fallback = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $result = $engine->generate(43, $source, $fallback);

        $this->assertSame('Titulo Gerado de Teste', $result->getTitle());
        $this->assertStringContainsString('Nenhuma pesquisa adicional disponivel', $provider->prompts[0]);
    }

    public function testTruncatedResponseRaisesAClearActionableErrorInsteadOfAGenericParseFailure(): void
    {
        $provider = new ScriptedProvider();
        $provider->simulateTruncatedResponse = true;
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'https://example.com/x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        try {
            $engine->generate(7, $source, $research);
            $this->fail('Expected a ContentException for the truncated response.');
        } catch (\RoboJackSparrow\Content\ContentException $e) {
            $this->assertStringContainsString('finish_reason=length', $e->getMessage());
            $this->assertStringContainsString('limite de tokens', $e->getMessage());
        }
    }
}

final class ScriptedProvider implements LLMProviderInterface
{
    /** @var string[] */
    public array $prompts = [];

    /** @var array<int, ?string> */
    public array $requestedModels = [];

    /**
     * When set, simulates a provider that hit its max_tokens ceiling mid
     * response - the JSON comes back cut off and finish_reason is 'length',
     * the exact signature ContentEngine::parseDraftOrFail() should detect
     * and report as a clear, actionable truncation error.
     */
    public bool $simulateTruncatedResponse = false;

    public function getName(): string
    {
        return 'test';
    }

    public function send(LLMRequest $request): LLMResponse
    {
        $prompt = $request->getPrompt();
        $this->prompts[] = $prompt;
        $this->requestedModels[] = $request->getModel();

        if ($this->simulateTruncatedResponse && str_contains($prompt, 'Escreva um artigo ORIGINAL')) {
            return new LLMResponse(
                content: '{"title":"Titulo Cortado","sections":[{"title":"So o inicio da fra',
                tokensUsed: 4096,
                promptTokens: 400,
                completionTokens: 3696,
                model: 'test-model',
                finishReason: 'length',
                rawResponse: []
            );
        }

        if (str_contains($prompt, 'Escreva um artigo ORIGINAL')) {
            return new LLMResponse(
                content: json_encode([
                    'title'            => 'Titulo Gerado de Teste',
                    'meta_description' => 'Uma meta descricao de teste bem curta.',
                    'sections'         => [
                        ['title' => 'Introducao', 'level' => 2, 'html' => '<p>Paragrafo de introducao gerado para teste.</p>'],
                        ['title' => 'Detalhes Tecnicos', 'level' => 2, 'html' => '<p>Paragrafo tecnico gerado para teste.</p>'],
                    ],
                    'faqs'             => [
                        ['question' => 'O que e isso?', 'answer' => 'Isso e um teste.'],
                        ['question' => 'Como funciona?', 'answer' => 'Funciona bem.'],
                    ],
                    'focus_keywords'   => ['palavra-chave-1', 'palavra-chave-2'],
                    'image_prompt'     => 'a friendly robot writing an article',
                ]),
                tokensUsed: 500,
                promptTokens: 400,
                completionTokens: 100,
                model: 'test-model',
                finishReason: 'stop',
                rawResponse: []
            );
        }

        throw new LLMException('Unexpected prompt in test: ' . substr($prompt, 0, 80));
    }
}
