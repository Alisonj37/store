<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Seo;

use RoboJackSparrow\Content\Dto\SeoData;

/**
 * Assembles SEO title/description and Article schema.org JSON-LD. No extra
 * LLM call is made here: the meta title/description already come out of
 * the article-generation step (see ContentEngine/ArticleDraftParser).
 */
class SeoGenerator
{
    private const MAX_TITLE_LENGTH = 60;
    private const MAX_DESCRIPTION_LENGTH = 160;

    public function generate(string $title, string $metaDescription, string $htmlContent): SeoData
    {
        $seoTitle = $this->truncate($title, self::MAX_TITLE_LENGTH);
        $description = $this->buildDescription($metaDescription, $htmlContent);

        return new SeoData(
            title: $seoTitle,
            description: $description,
            articleSchema: $this->buildArticleSchema($seoTitle, $description)
        );
    }

    private function buildDescription(string $metaDescription, string $htmlContent): string
    {
        $metaDescription = trim($metaDescription);
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
