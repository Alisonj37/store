<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Publisher;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Image\Dto\ImageAttribution;
use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Publisher\Dto\PublishRequest;
use RoboJackSparrow\Publisher\PublisherException;
use RoboJackSparrow\Publisher\WordPress\MediaUploader;
use RoboJackSparrow\Publisher\WordPress\PostPublisher;
use RoboJackSparrow\Publisher\WordPress\SeoIntegrator;
use RoboJackSparrow\Publisher\WordPress\TaxonomyManager;
use RoboJackSparrow\Tests\TestCase;

final class PostPublisherTest extends TestCase
{
    private function makePublisher(): PostPublisher
    {
        return new PostPublisher(new TaxonomyManager(), new MediaUploader(), new SeoIntegrator(), new Logger());
    }

    private function featuredImage(): ImageResult
    {
        return new ImageResult(
            binaryData: 'fake-png-bytes',
            mimeType: 'image/png',
            provider: 'unsplash',
            attribution: new ImageAttribution(authorName: 'Jane Photographer', sourceName: 'Unsplash'),
            sourceUrl: 'https://images.unsplash.com/photo-abc'
        );
    }

    public function testHappyPathCreatesPostWithNewCategoryTagsAndFeaturedImage(): void
    {
        $publisher = $this->makePublisher();

        $request = new PublishRequest(
            title: 'Titulo do Artigo <script>alert(1)</script>',
            htmlContent: '<p>Conteudo gerado.</p>',
            seoTitle: 'Titulo SEO',
            seoDescription: 'Descricao SEO',
            schemaArticle: ['@type' => 'Article'],
            schemaFaq: ['@type' => 'FAQPage'],
            focusKeywords: ['robo', 'automacao'],
            tags: ['robo', 'ia', ''],
            categoryName: 'Tecnologia',
            featuredImage: $this->featuredImage(),
            postStatus: 'publish'
        );

        $result = $publisher->publish($request);

        $this->assertSame(101, $result->getPostId());
        $this->assertSame('https://example.test/?p=101', $result->getPostUrl());
        $this->assertSame(500, $result->getFeaturedImageId());

        $stored = $this->wpdb->posts[101];
        $this->assertSame('Titulo do Artigo alert(1)', $stored['post_title']);
        $this->assertSame('publish', $stored['post_status']);
        $this->assertSame([200], $stored['post_category']);

        $this->assertSame(['robo', 'ia'], $this->wpdb->postTags[101]);
        $this->assertSame(500, $this->wpdb->thumbnails[101]);
        $this->assertSame('Foto por Jane Photographer / Unsplash', $this->wpdb->postMeta[500]['_rjs_image_credit']);
        $this->assertSame('Titulo SEO', $this->wpdb->postMeta[101]['_rjs_seo_title']);
    }

    public function testReusesAnExistingCategoryInsteadOfCreatingANewOne(): void
    {
        $this->wpdb->categories['Existente'] = 55;

        $request = new PublishRequest('T', '<p>x</p>', 'S', 'D', categoryName: 'Existente');
        $this->makePublisher()->publish($request);

        $this->assertSame([55], $this->wpdb->posts[101]['post_category']);
        $this->assertArrayNotHasKey('Tecnologia', $this->wpdb->categories);
    }

    public function testScheduledPublishSetsFutureStatusWithGmtDate(): void
    {
        $scheduledAt = new \DateTimeImmutable('2026-08-01 15:00:00', new \DateTimeZone('America/Sao_Paulo'));
        $request = new PublishRequest('T', '<p>x</p>', 'S', 'D', postStatus: 'draft', scheduledAt: $scheduledAt);

        $result = $this->makePublisher()->publish($request);
        $stored = $this->wpdb->posts[$result->getPostId()];

        $this->assertSame('future', $stored['post_status']);
        $this->assertSame('2026-08-01 15:00:00', $stored['post_date']);
        $this->assertSame(
            $scheduledAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $stored['post_date_gmt']
        );
    }

    public function testWpInsertPostFailureSurfacesAsPublisherException(): void
    {
        $this->wpdb->forceInsertPostFailure = true;

        $this->expectException(PublisherException::class);
        $this->expectExceptionMessageMatches('/Simulated DB failure/');
        $this->makePublisher()->publish(new PublishRequest('T', '<p>x</p>', 'S', 'D'));
    }

    public function testFeaturedImageUploadFailureIsNonFatal(): void
    {
        $this->wpdb->uploadFailureMessage = 'Disk quota exceeded';

        $request = new PublishRequest('T', '<p>x</p>', 'S', 'D', featuredImage: $this->featuredImage());
        $result = $this->makePublisher()->publish($request);

        $this->assertNull($result->getFeaturedImageId());
        $this->assertArrayNotHasKey($result->getPostId(), $this->wpdb->thumbnails);
    }

    public function testFeaturedImageFailureIsNonFatalEvenForANonPublisherExceptionThrowable(): void
    {
        // wp_insert_attachment()/wp_generate_attachment_metadata() are real
        // WP-core calls that can raise a raw TypeError/Error, not just our
        // own PublisherException - the article must still publish.
        $this->wpdb->throwTypeErrorOnAttachment = true;

        $request = new PublishRequest('T', '<p>x</p>', 'S', 'D', featuredImage: $this->featuredImage());
        $result = $this->makePublisher()->publish($request);

        $this->assertGreaterThan(0, $result->getPostId(), 'the post itself must still be published');
        $this->assertNull($result->getFeaturedImageId());
        $this->assertSame('S', $this->wpdb->postMeta[$result->getPostId()]['_rjs_seo_title'], 'SEO must still be applied after the image failure');
    }
}
