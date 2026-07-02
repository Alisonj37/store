<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Dto;

final class GeneratedContent
{
    /**
     * @param array<int, array{title: string, level: int, html: string, word_count: int, tokens: int}> $sections
     * @param Faq[] $faqs
     * @param string[] $focusKeywords
     */
    public function __construct(
        private string $title,
        private string $htmlContent,
        private string $seoTitle,
        private string $seoDescription,
        private array $schemaArticle,
        private array $schemaFaq,
        private array $focusKeywords,
        private array $faqs,
        private array $sections,
        private ?string $imagePrompt,
        private int $tokensUsed
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getHtmlContent(): string
    {
        return $this->htmlContent;
    }

    public function getSeoTitle(): string
    {
        return $this->seoTitle;
    }

    public function getSeoDescription(): string
    {
        return $this->seoDescription;
    }

    public function getSchemaArticle(): array
    {
        return $this->schemaArticle;
    }

    public function getSchemaFaq(): array
    {
        return $this->schemaFaq;
    }

    /**
     * @return string[]
     */
    public function getFocusKeywords(): array
    {
        return $this->focusKeywords;
    }

    /**
     * @return Faq[]
     */
    public function getFaqs(): array
    {
        return $this->faqs;
    }

    /**
     * @return array<int, array{title: string, level: int, html: string, word_count: int, tokens: int}>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    public function getImagePrompt(): ?string
    {
        return $this->imagePrompt;
    }

    /**
     * Tokens spent on outline + section generation. Does not include the
     * FAQ-extraction call (tracked separately by LLMRouter/Logger).
     */
    public function getTokensUsed(): int
    {
        return $this->tokensUsed;
    }
}
