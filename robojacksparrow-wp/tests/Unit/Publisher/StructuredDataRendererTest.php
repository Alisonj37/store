<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Publisher;

use RoboJackSparrow\Publisher\WordPress\StructuredDataRenderer;
use RoboJackSparrow\Tests\TestCase;

final class StructuredDataRendererTest extends TestCase
{
    public function testOutputsBothArticleAndFaqSchemaAsLdJson(): void
    {
        $this->wpdb->postMeta[42] = [
            '_rjs_schema_article' => json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Titulo']),
            '_rjs_schema_faq'     => json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []]),
        ];

        $html = (new StructuredDataRenderer())->buildMarkup(42);

        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertSame(2, substr_count($html, '<script'));
    }

    public function testOutputsNothingWhenThePostHasNoSchemaMeta(): void
    {
        $html = (new StructuredDataRenderer())->buildMarkup(999);

        $this->assertSame('', $html);
    }

    public function testIgnoresMalformedJsonInsteadOfCrashing(): void
    {
        $this->wpdb->postMeta[7] = ['_rjs_schema_article' => 'not valid json'];

        $html = (new StructuredDataRenderer())->buildMarkup(7);

        $this->assertSame('', $html);
    }

    public function testOnlyArticleSchemaPresentStillRendersThatOne(): void
    {
        $this->wpdb->postMeta[8] = ['_rjs_schema_article' => json_encode(['@type' => 'Article'])];

        $html = (new StructuredDataRenderer())->buildMarkup(8);

        $this->assertSame(1, substr_count($html, '<script'));
    }
}
