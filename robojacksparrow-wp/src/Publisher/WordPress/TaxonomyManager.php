<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

class TaxonomyManager
{
    /**
     * Resolves a category name to a term ID, creating the category if it
     * doesn't exist yet. Falls back to the site's default category when no
     * name is given or creation fails.
     */
    public function resolveCategoryId(?string $categoryName): int
    {
        if ($categoryName === null || trim($categoryName) === '') {
            return (int) get_option('default_category');
        }

        $existing = get_term_by('name', $categoryName, 'category');
        if ($existing !== false && $existing !== null) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($categoryName, 'category');
        if (is_wp_error($created)) {
            return (int) get_option('default_category');
        }

        return (int) $created['term_id'];
    }

    /**
     * @param string[] $tags
     */
    public function assignTags(int $postId, array $tags): void
    {
        $tags = array_values(array_filter(
            array_map('trim', $tags),
            static fn (string $tag) => $tag !== ''
        ));

        if ($tags === []) {
            return;
        }

        wp_set_post_tags($postId, $tags, false);
    }
}
