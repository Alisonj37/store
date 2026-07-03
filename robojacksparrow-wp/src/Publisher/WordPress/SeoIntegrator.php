<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

use RoboJackSparrow\Publisher\Dto\PublishRequest;

/**
 * Integra os metadados de SEO gerados pelo Content Engine com o plugin de
 * SEO ativo no site (Yoast, RankMath ou SEOPress, nessa ordem de deteccao).
 * Se nenhum estiver ativo, os dados ainda sao persistidos como postmeta
 * proprio do RoboJackSparrow, para uso futuro (fallback no <head> ou na
 * propria Admin UI).
 */
class SeoIntegrator
{
    /**
     * @return string O plugin de SEO detectado ('yoast'|'rankmath'|'seopress'|'none').
     */
    public function apply(int $postId, PublishRequest $request): string
    {
        $integration = $this->detectActiveIntegration();

        match ($integration) {
            'yoast'    => $this->applyYoast($postId, $request),
            'rankmath' => $this->applyRankMath($postId, $request),
            'seopress' => $this->applySeoPress($postId, $request),
            default    => null,
        };

        $this->applyOwnMeta($postId, $request);

        return $integration;
    }

    private function detectActiveIntegration(): string
    {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }

        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'rankmath';
        }

        if (defined('SEOPRESS_VERSION') || function_exists('seopress_init')) {
            return 'seopress';
        }

        return 'none';
    }

    private function applyYoast(int $postId, PublishRequest $request): void
    {
        update_post_meta($postId, '_yoast_wpseo_title', $request->getSeoTitle());
        update_post_meta($postId, '_yoast_wpseo_metadesc', $request->getSeoDescription());

        if ($request->getFocusKeywords() !== []) {
            update_post_meta($postId, '_yoast_wpseo_focuskw', $request->getFocusKeywords()[0]);
        }
    }

    private function applyRankMath(int $postId, PublishRequest $request): void
    {
        update_post_meta($postId, 'rank_math_title', $request->getSeoTitle());
        update_post_meta($postId, 'rank_math_description', $request->getSeoDescription());

        if ($request->getFocusKeywords() !== []) {
            update_post_meta($postId, 'rank_math_focus_keyword', implode(',', $request->getFocusKeywords()));
        }
    }

    private function applySeoPress(int $postId, PublishRequest $request): void
    {
        update_post_meta($postId, '_seopress_titles_title', $request->getSeoTitle());
        update_post_meta($postId, '_seopress_titles_desc', $request->getSeoDescription());

        if ($request->getFocusKeywords() !== []) {
            update_post_meta($postId, '_seopress_analysis_target_kw', $request->getFocusKeywords()[0]);
        }
    }

    private function applyOwnMeta(int $postId, PublishRequest $request): void
    {
        update_post_meta($postId, '_rjs_seo_title', $request->getSeoTitle());
        update_post_meta($postId, '_rjs_seo_description', $request->getSeoDescription());

        $articleSchema = $request->getSchemaArticle();
        if ($articleSchema !== []) {
            update_post_meta($postId, '_rjs_schema_article', wp_json_encode($this->enrichArticleSchema($postId, $articleSchema)));
        }

        if ($request->getSchemaFaq() !== []) {
            update_post_meta($postId, '_rjs_schema_faq', wp_json_encode($request->getSchemaFaq()));
        }
    }

    /**
     * ContentEngine/SeoGenerator build the Article schema before the post
     * even exists, so it only has headline/description/dateModified - the
     * fields that actually make Article structured data useful for Google
     * rich results and AI answer engines (canonical URL, real publish date,
     * author/publisher, image) all depend on the post that was just
     * created, so they're filled in here instead.
     */
    private function enrichArticleSchema(int $postId, array $schema): array
    {
        $permalink = (string) get_permalink($postId);

        $schema['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $permalink];
        $schema['url'] = $permalink;
        $schema['datePublished'] = (string) get_the_date('c', $postId);
        $schema['dateModified'] = (string) (get_the_modified_date('c', $postId) ?: $schema['dateModified'] ?? $schema['datePublished']);

        $siteName = (string) get_bloginfo('name');
        $schema['author'] = ['@type' => 'Organization', 'name' => $siteName];
        $schema['publisher'] = ['@type' => 'Organization', 'name' => $siteName];

        $imageUrl = get_the_post_thumbnail_url($postId, 'full');
        if (is_string($imageUrl) && $imageUrl !== '') {
            $schema['image'] = [$imageUrl];
        }

        return $schema;
    }
}
