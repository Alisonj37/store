<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

use DateTimeZone;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Publisher\Dto\PublishRequest;
use RoboJackSparrow\Publisher\Dto\PublishResult;
use RoboJackSparrow\Publisher\PublisherException;
use Throwable;

/**
 * Wrapper de wp_insert_post: cria/agenda o post, resolve categoria/tags,
 * anexa a imagem de destaque e aplica os metadados de SEO.
 */
class PostPublisher
{
    public function __construct(
        private TaxonomyManager $taxonomy,
        private MediaUploader $media,
        private SeoIntegrator $seo,
        private Logger $logger
    ) {
    }

    public function publish(PublishRequest $request): PublishResult
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        ignore_user_abort(true);

        $categoryId = $this->taxonomy->resolveCategoryId($request->getCategoryName());

        $postData = [
            'post_title'    => wp_strip_all_tags($request->getTitle()),
            'post_content'  => $request->getHtmlContent(),
            'post_status'   => $request->getPostStatus(),
            'post_type'     => $request->getPostType(),
            'post_category' => $categoryId > 0 ? [$categoryId] : [],
        ];

        if ($request->getScheduledAt() !== null) {
            $postData['post_status'] = 'future';
            $postData['post_date'] = $request->getScheduledAt()->format('Y-m-d H:i:s');
            $postData['post_date_gmt'] = $request->getScheduledAt()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            throw new PublisherException('Failed to insert post: ' . $postId->get_error_message());
        }

        $this->taxonomy->assignTags($postId, $request->getTags());

        $featuredImageId = $this->tryAttachFeaturedImage($postId, $request);

        $this->seo->apply($postId, $request);

        return new PublishResult(
            postId: $postId,
            postUrl: (string) get_permalink($postId),
            featuredImageId: $featuredImageId
        );
    }

    /**
     * A featured image failure must not lose an otherwise-valid article:
     * log it and publish without one instead of throwing. Catches every
     * Throwable, not just PublisherException - MediaUploader calls several
     * native WP functions (wp_upload_bits, wp_insert_attachment,
     * wp_generate_attachment_metadata) that can raise a raw TypeError/Error
     * (malformed image, a third-party media hook misbehaving) rather than
     * our own exception type, and those must degrade the same way.
     */
    private function tryAttachFeaturedImage(int $postId, PublishRequest $request): ?int
    {
        $image = $request->getFeaturedImage();
        if ($image === null) {
            return null;
        }

        try {
            return $this->media->attachFeaturedImage($postId, $image, $request->getTitle());
        } catch (Throwable $e) {
            $this->logger->warning('Failed to attach featured image, publishing without one', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
