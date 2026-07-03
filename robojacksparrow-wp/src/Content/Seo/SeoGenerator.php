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

    /**
     * @param string[] $focusKeywords The article's focus keywords, primary
     *     one first (see ArticleDraft::getFocusKeywords()). Rank Math/Yoast
     *     both weigh "focus keyword appears in the SEO title and meta
     *     description" heavily; the generation prompt now asks the LLM for
     *     this directly, but ensureKeywordPresent() is a deterministic
     *     safety net for whenever it doesn't come back that way.
     */
    public function generate(string $title, string $metaDescription, string $htmlContent, array $focusKeywords = []): SeoData
    {
        $primaryKeyword = trim((string) ($focusKeywords[0] ?? ''));

        $seoTitle = $this->ensureKeywordPresent(
            $this->truncate($title, self::MAX_TITLE_LENGTH),
            $primaryKeyword,
            self::MAX_TITLE_LENGTH
        );

        $description = $this->ensureKeywordPresent(
            $this->buildDescription($metaDescription, $htmlContent),
            $primaryKeyword,
            self::MAX_DESCRIPTION_LENGTH
        );

        return new SeoData(
            title: $seoTitle,
            description: $description,
            articleSchema: $this->buildArticleSchema($seoTitle, $description)
        );
    }

    private function ensureKeywordPresent(string $text, string $keyword, int $maxLength): string
    {
        if ($keyword === '' || mb_stripos($text, $keyword) !== false) {
            return $text;
        }

        $combined = $text . ' - ' . $keyword;
        if (mb_strlen($combined) <= $maxLength) {
            return $combined;
        }

        // No room to append: lead with the keyword instead (truncating the
        // original text to make space) rather than silently dropping it.
        $prefix = $keyword . ': ';
        $available = $maxLength - mb_strlen($prefix);

        if ($available <= 10) {
            return $this->truncate($keyword, $maxLength);
        }

        return $prefix . $this->truncate($text, $available);
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
