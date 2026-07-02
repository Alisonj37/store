<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Seo;

use RoboJackSparrow\Content\Dto\Outline;
use RoboJackSparrow\Content\Dto\SeoData;

/**
 * Assembles SEO title/description and Article schema.org JSON-LD from
 * already-generated outline/content data. No extra LLM call is made here:
 * the meta title/description and focus keywords already come out of the
 * outline-generation step.
 */
class SeoGenerator
{
    private const MAX_TITLE_LENGTH = 60;
    private const MAX_DESCRIPTION_LENGTH = 160;

    public function generate(Outline $outline, string $htmlContent): SeoData
    {
        $title = $this->truncate($outline->getTitle(), self::MAX_TITLE_LENGTH);
        $description = $this->buildDescription($outline, $htmlContent);

        return new SeoData(
            title: $title,
            description: $description,
            articleSchema: $this->buildArticleSchema($title, $description)
        );
    }

    private function buildDescription(Outline $outline, string $htmlContent): string
    {
        $metaDescription = trim($outline->getMetaDescription());
        if ($metaDescription !== '') {
            return $this->truncate($metaDescription, self::MAX_DESCRIPTION_LENGTH);
        }

        $plainText = trim((string) preg_replace('/\s+/', ' ', strip_tags($htmlContent)));

        return $this->truncate($plainText, self::MAX_DESCRIPTION_LENGTH);
    }

    private function buildArticleSchema(string $title, string $description): array
    {
        return [
            '@context'     => 'https://schema.org',
            '@type'        => 'Article',
            'headline'     => $title,
            'description'  => $description,
            'dateModified' => gmdate('c'),
        ];
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }
}
