<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Publisher;

use RoboJackSparrow\Publisher\Dto\PublishRequest;
use RoboJackSparrow\Publisher\WordPress\SeoIntegrator;
use RoboJackSparrow\Tests\TestCase;

/**
 * WPSEO_VERSION / RANK_MATH_VERSION / SEOPRESS_VERSION are real PHP
 * constants and cannot be undefined once set. This suite relies on
 * PHPUnit's default same-process, declaration-order execution to define
 * them progressively in ascending priority (none -> seopress -> rankmath
 * -> yoast) across its numbered test methods, proving at each step that
 * SeoIntegrator's detection order (Yoast > RankMath > SEOPress) holds
 * even when multiple "plugins" appear active simultaneously.
 */
final class SeoIntegratorTest extends TestCase
{
    public function test1NoSeoPluginActiveStoresOnlyOwnPostmeta(): void
    {
        $seo = new SeoIntegrator();
        $request = new PublishRequest('T', '<p>x</p>', 'SEO Title', 'SEO Desc', schemaArticle: ['@type' => 'Article']);

        $integration = $seo->apply(900, $request);

        $this->assertSame('none', $integration);
        $this->assertSame('SEO Title', $this->wpdb->postMeta[900]['_rjs_seo_title']);
        $this->assertArrayNotHasKey('_yoast_wpseo_title', $this->wpdb->postMeta[900]);
    }

    /**
     * ContentEngine/SeoGenerator build the Article schema before the post
     * exists, so it's missing everything that actually makes it useful for
     * Google rich results / AI answer engines (canonical URL, real publish
     * date, author/publisher, image) - SeoIntegrator must fill those in
     * once the post (and its featured image) actually exist.
     */
    public function testArticleSchemaIsEnrichedWithPostSpecificFieldsBeforeBeingStored(): void
    {
        $this->wpdb->thumbnails[950] = 500;
        $this->wpdb->attachmentUrls[500] = 'https://example.test/uploads/featured.png';

        $seo = new SeoIntegrator();
        $request = new PublishRequest('T', '<p>x</p>', 'SEO Title', 'SEO Desc', schemaArticle: ['@type' => 'Article', 'headline' => 'T']);

        $seo->apply(950, $request);

        $schema = json_decode((string) $this->wpdb->postMeta[950]['_rjs_schema_article'], true);

        $this->assertSame('https://example.test/?p=950', $schema['url']);
        $this->assertSame('https://example.test/?p=950', $schema['mainEntityOfPage']['@id']);
        $this->assertNotEmpty($schema['datePublished']);
        $this->assertSame('Organization', $schema['author']['@type']);
        $this->assertSame(['https://example.test/uploads/featured.png'], $schema['image']);
    }

    public function test2SeoPressIsDetectedAndWritesItsOwnKeys(): void
    {
        define('SEOPRESS_VERSION', '7.0');

        $seo = new SeoIntegrator();
        $request = new PublishRequest('T', '<p>x</p>', 'SEO Title', 'SEO Desc', focusKeywords: ['kw1']);

        $integration = $seo->apply(901, $request);

        $this->assertSame('seopress', $integration);
        $this->assertSame('SEO Title', $this->wpdb->postMeta[901]['_seopress_titles_title']);
    }

    public function test3RankMathTakesPriorityOverSeoPress(): void
    {
        define('RANK_MATH_VERSION', '1.0');

        $seo = new SeoIntegrator();
        $request = new PublishRequest('T', '<p>x</p>', 'SEO Title', 'SEO Desc', focusKeywords: ['kw1']);

        $integration = $seo->apply(902, $request);

        $this->assertSame('rankmath', $integration);
        $this->assertSame('SEO Title', $this->wpdb->postMeta[902]['rank_math_title']);
    }

    public function test4YoastTakesTopPriorityOverRankMathAndSeoPress(): void
    {
        define('WPSEO_VERSION', '20.0');

        $seo = new SeoIntegrator();
        $request = new PublishRequest('T', '<p>x</p>', 'SEO Title', 'SEO Desc', focusKeywords: ['kw1']);

        $integration = $seo->apply(903, $request);

        $this->assertSame('yoast', $integration);
        $this->assertSame('SEO Title', $this->wpdb->postMeta[903]['_yoast_wpseo_title']);
        $this->assertSame('kw1', $this->wpdb->postMeta[903]['_yoast_wpseo_focuskw']);
    }
}
