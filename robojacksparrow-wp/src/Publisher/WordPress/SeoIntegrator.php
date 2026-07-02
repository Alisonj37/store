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

        if ($request->getSchemaArticle() !== []) {
            update_post_meta($postId, '_rjs_schema_article', wp_json_encode($request->getSchemaArticle()));
        }

        if ($request->getSchemaFaq() !== []) {
            update_post_meta($postId, '_rjs_schema_faq', wp_json_encode($request->getSchemaFaq()));
        }
    }
}
