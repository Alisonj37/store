<?php

declare(strict_types=1);

namespace RoboJackSparrow\Publisher\WordPress;

use RoboJackSparrow\Image\Dto\ImageResult;
use RoboJackSparrow\Publisher\PublisherException;

/**
 * Sideloads a downloaded/generated image (Image Engine, Fase 6) into the
 * WordPress media library and sets it as a post's featured image.
 */
class MediaUploader
{
    public function attachFeaturedImage(int $postId, ImageResult $image, string $title, string $altText = ''): int
    {
        $attachmentId = $this->upload($postId, $image, $title, $altText !== '' ? $altText : $title);

        set_post_thumbnail($postId, $attachmentId);

        return $attachmentId;
    }

    /**
     * Uploads an in-body image (not the featured one) to the media library
     * and returns its public URL, for substituting a content placeholder
     * token - see ContentEngine::planBodyImages()/PostPublisher.
     */
    public function uploadInlineImage(int $postId, ImageResult $image, string $title, string $altText): string
    {
        $attachmentId = $this->upload($postId, $image, $title, $altText);

        return (string) wp_get_attachment_url($attachmentId);
    }

    private function upload(int $postId, ImageResult $image, string $title, string $altText): int
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $filename = $this->buildFilename($title, $image->getMimeType());

        $upload = wp_upload_bits($filename, null, $image->getBinaryData());
        if (!empty($upload['error'])) {
            throw new PublisherException('Failed to upload image: ' . $upload['error']);
        }

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $image->getMimeType(),
                'post_title'     => $title,
                'post_status'    => 'inherit',
            ],
            $upload['file'],
            $postId
        );

        if (is_wp_error($attachmentId) || !$attachmentId) {
            $message = is_wp_error($attachmentId) ? $attachmentId->get_error_message() : 'unknown error';
            throw new PublisherException('Failed to create media attachment: ' . $message);
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        wp_update_attachment_metadata($attachmentId, $metadata);

        $this->applyAttribution($attachmentId, $image);

        // Rank Math/Yoast and basic accessibility both check for image alt
        // text - previously never set, meaning every generated image failed
        // that check regardless of anything else about the article.
        if (trim($altText) !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
        }

        return $attachmentId;
    }

    private function buildFilename(string $title, string $mimeType): string
    {
        $slug = sanitize_title($title);
        if ($slug === '') {
            $slug = 'robojacksparrow-image-' . time();
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'png',
        };

        return $slug . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $extension;
    }

    private function applyAttribution(int $attachmentId, ImageResult $image): void
    {
        $credit = $image->getAttribution()->toCreditText();
        if ($credit !== null) {
            update_post_meta($attachmentId, '_rjs_image_credit', $credit);
        }

        if ($image->getSourceUrl() !== null) {
            update_post_meta($attachmentId, '_rjs_image_source_url', $image->getSourceUrl());
        }

        update_post_meta($attachmentId, '_rjs_image_provider', $image->getProvider());
    }
}
