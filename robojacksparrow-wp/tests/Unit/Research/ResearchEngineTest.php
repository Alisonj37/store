<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Research;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Research\ResearchEngine;
use RoboJackSparrow\Research\TavilyDriver;
use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class ResearchEngineTest extends TestCase
{
    private ScrapedContent $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = new ScrapedContent(
            url: 'https://example.com/original-article',
            title: 'Anthropic Claude 5',
            text: 'Anthropic released a new model. It is called Claude 5. Developers are excited about it.',
            html: null
        );
    }

    public function testEnrichUsesTavilyAnswerFactsAndSourcesWhenConfigured(): void
    {
        HttpFixtures::set('POST', 'https://api.tavily.com/search', [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'answer'  => 'Anthropic launched Claude 5 focused on coding agents.',
                'results' => [
                    ['url' => 'https://example.com/news/claude-5', 'title' => 'Anthropic launches Claude 5', 'content' => 'Anthropic announced Claude 5 today. It focuses on coding agents.', 'score' => 0.95],
                ],
            ]),
        ]);

        $engine = new ResearchEngine(new TavilyDriver('tvly-key'), new Logger());
        $data = $engine->enrich($this->source);

        $this->assertFalse($data->isFromFallback());
        $this->assertSame('Anthropic launched Claude 5 focused on coding agents.', $data->getAnswer());
        $this->assertCount(1, $data->getSources());
        $this->assertStringContainsString('Resumo verificado:', $data->toBriefing());
        $this->assertStringContainsString('https://example.com/news/claude-5', $data->toBriefing());
    }

    public function testEnrichFallsBackToScrapedContentWhenTavilyFails(): void
    {
        HttpFixtures::set('POST', 'https://api.tavily.com/search', new \WP_Error('http_request_failed', 'network down'));

        $engine = new ResearchEngine(new TavilyDriver('tvly-key'), new Logger());
        $data = $engine->enrich($this->source);

        $this->assertTrue($data->isFromFallback());
        $this->assertNull($data->getAnswer());
        $this->assertCount(1, $data->getFacts());
        $this->assertSame('https://example.com/original-article', $data->getSources()[0]->getUrl());
    }

    public function testEnrichFallsBackImmediatelyWithoutAnyKeyConfigured(): void
    {
        $engine = new ResearchEngine(new TavilyDriver(''), new Logger());
        $data = $engine->enrich($this->source);

        $this->assertTrue($data->isFromFallback());
        $this->assertSame(0, HttpFixtures::callCount('POST', 'https://api.tavily.com/search'), 'must not attempt a network call without a key');
    }

    public function testEnrichFallsBackWhenDriverIsNull(): void
    {
        $engine = new ResearchEngine(null, new Logger());

        $this->assertTrue($engine->enrich($this->source)->isFromFallback());
    }
}
