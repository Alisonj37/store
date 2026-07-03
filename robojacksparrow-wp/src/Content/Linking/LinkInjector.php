<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Linking;

/**
 * Appends an internal-linking ("Leia tambem") block pointing at other
 * already-published articles on the site. Kept separate from ContentEngine
 * (which handles external/citation links via ResearchData) because it
 * needs rows sourced from the WordPress-facing ArticleRepository, not just
 * the LLM generation inputs ContentEngine otherwise depends on.
 */
class LinkInjector
{
    /**
     * @param object[] $relatedArticles Rows with source_title + wordpress_post_url (see ArticleRepository::findRelatedPublished()).
     */
    public function appendInternalLinks(string $html, array $relatedArticles): string
    {
        $items = '';

        foreach ($relatedArticles as $article) {
            $title = trim((string) ($article->source_title ?? ''));
            $url = trim((string) ($article->wordpress_post_url ?? ''));

            if ($title === '' || $url === '') {
                continue;
            }

            $items .= sprintf("<li><a href=\"%s\">%s</a></li>\n", esc_url($url), esc_html($title));
        }

        if ($items === '') {
            return $html;
        }

        return $html . "\n\n<h2>Leia tambem</h2>\n<ul>\n{$items}</ul>\n";
    }
}
