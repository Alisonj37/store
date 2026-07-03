<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content;

use RoboJackSparrow\Ai\Contracts\LLMProviderInterface;
use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\Dto\LLMResponse;
use RoboJackSparrow\Ai\HealthMonitor;
use RoboJackSparrow\Ai\LLMException;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Ai\Memory\MemoryBuffer;
use RoboJackSparrow\Ai\Memory\MemoryStore;
use RoboJackSparrow\Content\ContentEngine;
use RoboJackSparrow\Content\Faq\FaqExtractor;
use RoboJackSparrow\Content\Outline\OutlineGenerator;
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
    private function makeEngine(ScriptedProvider $provider): ContentEngine
    {
        $llm = new LLMRouter(['test' => $provider], new HealthMonitor(), new Logger());
        $prompts = new PromptLibrary();
        $memory = new MemoryBuffer(new MemoryStore());

        return new ContentEngine(
            $llm,
            $memory,
            new SeoGenerator(),
            new FaqExtractor($llm, $prompts),
            new OutlineGenerator(),
            $prompts,
            new SettingRepository(new Encryption(), new Logger()),
            new Logger()
        );
    }

    public function testFullPipelineProducesTitleSectionsFaqAndSeo(): void
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
        $this->assertSame(1050, $result->getTokensUsed(), 'outline(500) + 2 sections(200 each) + faq(150) = 1050');

        // Proves the Tavily briefing is actually injected into the prompts.
        $this->assertStringContainsString('Resposta verificada ABC sobre o lancamento do robo.', $provider->prompts[0]);
        $this->assertStringContainsString('Resposta verificada ABC sobre o lancamento do robo.', $provider->prompts[1]);

        // External citations from the research sources (AEO/GEO: answer
        // engines weigh cited, checkable sources).
        $this->assertStringContainsString('<h2>Fontes</h2>', $result->getHtmlContent());
        $this->assertStringContainsString('<a href="https://example.com/fonte" target="_blank" rel="noopener noreferrer">Fonte Exemplo Verificada</a>', $result->getHtmlContent());
    }

    public function testNoSourcesBlockIsAddedWhenResearchHasNoSources(): void
    {
        $provider = new ScriptedProvider();
        $engine = $this->makeEngine($provider);

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData(facts: [], sources: [], answer: null, entities: [], fromFallback: true);

        $result = $engine->generate(1, $source, $research);

        $this->assertStringNotContainsString('<h2>Fontes</h2>', $result->getHtmlContent());
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

    public function testMemoryIsIsolatedPerArticle(): void
    {
        $store = new MemoryStore();
        $llm = new LLMRouter(['test' => new ScriptedProvider()], new HealthMonitor(), new Logger());
        $prompts = new PromptLibrary();
        $engine = new ContentEngine($llm, new MemoryBuffer($store), new SeoGenerator(), new FaqExtractor($llm, $prompts), new OutlineGenerator(), $prompts, new SettingRepository(new Encryption(), new Logger()), new Logger());

        $source = new ScrapedContent(url: 'x', title: 'X', text: 'text', html: null);
        $research = new ResearchData([], [], null, [], true);

        $engine->generate(1, $source, $research);
        $engine->generate(2, $source, $research);

        // 2 sections x (user, assistant) pair each = 4 entries per article.
        $this->assertCount(4, $store->getAll('article_1'));
        $this->assertCount(4, $store->getAll('article_2'));
    }
}

final class ScriptedProvider implements LLMProviderInterface
{
    /** @var string[] */
    public array $prompts = [];

    public function getName(): string
    {
        return 'test';
    }

    public function send(LLMRequest $request): LLMResponse
    {
        $prompt = $request->getPrompt();
        $this->prompts[] = $prompt;

        if (str_contains($prompt, 'Crie a estrutura de um artigo original')) {
            return new LLMResponse(
                content: json_encode([
                    'title'            => 'Titulo Gerado de Teste',
                    'meta_description' => 'Uma meta descricao de teste bem curta.',
                    'sections'         => [
                        ['title' => 'Introducao', 'level' => 2],
                        ['title' => 'Detalhes Tecnicos', 'level' => 2],
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

        if (str_contains($prompt, 'Voce esta escrevendo a secao')) {
            return new LLMResponse('<p>Paragrafo gerado para a secao de teste.</p>', 200, 150, 50, 'test-model', 'stop', []);
        }

        if (str_contains($prompt, 'extraia de 3 a 6 perguntas')) {
            return new LLMResponse(
                content: json_encode([
                    ['question' => 'O que e isso?', 'answer' => 'Isso e um teste.'],
                    ['question' => 'Como funciona?', 'answer' => 'Funciona bem.'],
                ]),
                tokensUsed: 150,
                promptTokens: 120,
                completionTokens: 30,
                model: 'test-model',
                finishReason: 'stop',
                rawResponse: []
            );
        }

        throw new LLMException('Unexpected prompt in test: ' . substr($prompt, 0, 80));
    }
}
