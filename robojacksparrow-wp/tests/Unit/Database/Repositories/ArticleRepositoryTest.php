<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Database\Repositories;

use RoboJackSparrow\Database\Repositories\ArticleRepository;
use RoboJackSparrow\Tests\TestCase;

final class ArticleRepositoryTest extends TestCase
{
    public function testFindRelatedPublishedPrefersSameCategory(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'wordpress_post_url' => 'https://x.test/1', 'source_title' => 'Mesma categoria', 'category_name' => 'Tech', 'created_at' => '2026-01-01 00:00:00'],
            2 => ['id' => 2, 'status' => 'published', 'wordpress_post_url' => 'https://x.test/2', 'source_title' => 'Outra categoria', 'category_name' => 'Esportes', 'created_at' => '2026-01-02 00:00:00'],
            3 => ['id' => 3, 'status' => 'pending', 'wordpress_post_url' => null, 'source_title' => 'Nao publicado', 'category_name' => 'Tech', 'created_at' => '2026-01-03 00:00:00'],
        ];

        $repo = new ArticleRepository();
        $related = $repo->findRelatedPublished(99, 'Tech');

        $this->assertCount(1, $related);
        $this->assertSame('Mesma categoria', $related[0]->source_title);
    }

    public function testFindRelatedPublishedReturnsNothingWhenCategoryHasNoMatches(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'wordpress_post_url' => 'https://x.test/1', 'source_title' => 'Artigo', 'category_name' => 'Esportes', 'created_at' => '2026-01-01 00:00:00'],
        ];

        $repo = new ArticleRepository();
        $related = $repo->findRelatedPublished(99, 'Tech');

        $this->assertSame([], $related, 'no Tech-category article exists - must not fall back to an unrelated published article');
    }

    public function testFindRelatedPublishedReturnsNothingWithoutACategory(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'wordpress_post_url' => 'https://x.test/1', 'source_title' => 'Artigo', 'category_name' => 'Tech', 'created_at' => '2026-01-01 00:00:00'],
        ];

        $repo = new ArticleRepository();
        $related = $repo->findRelatedPublished(99, null);

        $this->assertSame([], $related, 'with no category on the new article, there is nothing to reliably match against');
    }

    public function testFindRelatedPublishedExcludesTheArticleItself(): void
    {
        $this->wpdb->articles = [
            1 => ['id' => 1, 'status' => 'published', 'wordpress_post_url' => 'https://x.test/1', 'source_title' => 'Artigo', 'category_name' => 'Tech', 'created_at' => '2026-01-01 00:00:00'],
        ];

        $repo = new ArticleRepository();
        $related = $repo->findRelatedPublished(1, 'Tech');

        $this->assertSame([], $related);
    }
}
