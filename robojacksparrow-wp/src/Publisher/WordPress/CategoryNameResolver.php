<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

/**
 * Resolves a WP category term ID to its name. The articles table stores
 * category_name (a plain string), not category_id, as the source of truth
 * for publishing (see JobRegistry::handlePublish() / TaxonomyManager) -
 * anything that creates an article from a category_id (Gerar Artigo,
 * RssCron, ScraperCron) must resolve the name at creation time, or the
 * configured category is silently ignored at publish time.
 */
final class CategoryNameResolver
{
    public static function nameFor(?int $categoryId): ?string
    {
        if ($categoryId === null || $categoryId <= 0 || !function_exists('get_term')) {
            return null;
        }

        $term = get_term($categoryId, 'category');

        return ($term !== null && !is_wp_error($term)) ? (string) $term->name : null;
    }
}
