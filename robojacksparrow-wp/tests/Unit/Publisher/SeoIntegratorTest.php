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
