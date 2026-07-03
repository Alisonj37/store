<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

use DateTimeZone;
use RoboJackSparrow\Content\ContentEngine;
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
            // Body-image placeholder tokens (see ContentEngine::planBodyImages())
            // are still in here as plain HTML comments at insert time - each
            // image needs a postId to attach to, which doesn't exist until
            // after wp_insert_post() runs, so substitution happens below and
            // is written back with a follow-up wp_update_post().
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

        $this->resolveBodyImages($postId, $request);

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

        $altText = $request->getFocusKeywords()[0] ?? $request->getTitle();

        try {
            return $this->media->attachFeaturedImage($postId, $image, $request->getTitle(), $altText);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to attach featured image, publishing without one', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Uploads each in-body image and swaps its placeholder token (embedded
     * directly in the HTML by ContentEngine::planBodyImages()) for a real
     * <img> tag pointing at the uploaded attachment. Any image that failed
     * to generate/upload just has its placeholder token stripped instead of
     * leaving a broken comment (or, worse, failing the whole publish) - the
     * same graceful-degradation approach as the featured image.
     */
    private function resolveBodyImages(int $postId, PublishRequest $request): void
    {
        $content = $request->getHtmlContent();
        if (!str_contains($content, '<!--RJS_BODY_IMAGE_')) {
            return;
        }

        $changed = false;

        foreach ($request->getBodyImages() as $bodyImage) {
            $placeholder = ContentEngine::placeholderFor($bodyImage->getToken());

            if (!str_contains($content, $placeholder)) {
                continue;
            }

            try {
                $url = $this->media->uploadInlineImage($postId, $bodyImage->getImage(), $request->getTitle(), $bodyImage->getAltText());
                $imgTag = sprintf(
                    '<img src="%s" alt="%s" loading="lazy" />',
                    esc_url($url),
                    esc_attr($bodyImage->getAltText())
                );
                $content = str_replace($placeholder, $imgTag, $content);
                $changed = true;
            } catch (Throwable $e) {
                $this->logger->warning('Failed to upload a body image, dropping its placeholder', [
                    'post_id' => $postId,
                    'token'   => $bodyImage->getToken(),
                    'error'   => $e->getMessage(),
                ]);

                $content = str_replace($placeholder, '', $content);
                $changed = true;
            }
        }

        // Strip any placeholder left over from an image that never even
        // reached this method (e.g. the whole generate_image job failed
        // before any body image was attempted) so a bare HTML comment never
        // ships in the published post.
        $content = (string) preg_replace('/<!--RJS_BODY_IMAGE_\d+-->/', '', $content, -1, $count);
        $changed = $changed || $count > 0;

        if ($changed) {
            wp_update_post(['ID' => $postId, 'post_content' => $content]);
        }
    }
}
