<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

/**
 * SeoIntegrator stores the Article/FAQ schema.org JSON-LD as postmeta
 * (_rjs_schema_article/_rjs_schema_faq) at publish time, but nothing ever
 * echoed it anywhere on the front-end - the single most important piece for
 * GEO/AEO (Google rich results, AI answer engines reading structured data)
 * was being silently discarded. This hooks wp_head to actually output it.
 */
final class StructuredDataRenderer
{
    public function register(): void
    {
        add_action('wp_head', [$this, 'renderForCurrentPost']);
    }

    public function renderForCurrentPost(): void
    {
        if (!is_singular()) {
            return;
        }

        $postId = (int) get_the_ID();
        if ($postId <= 0) {
            return;
        }

        echo $this->buildMarkup($postId);
    }

    /**
     * Split out from renderForCurrentPost() so the actual markup-building
     * logic can be unit tested directly against a known post ID, without
     * depending on WP's global post/loop state (is_singular()/get_the_ID()).
     */
    public function buildMarkup(int $postId): string
    {
        $schemas = array_filter([
            $this->decodeSchema(get_post_meta($postId, '_rjs_schema_article', true)),
            $this->decodeSchema(get_post_meta($postId, '_rjs_schema_faq', true)),
        ]);

        if ($schemas === []) {
            return '';
        }

        $html = '';
        foreach ($schemas as $schema) {
            $html .= '<script type="application/ld+json">' . wp_json_encode($schema) . "</script>\n";
        }

        return $html;
    }

    private function decodeSchema(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
