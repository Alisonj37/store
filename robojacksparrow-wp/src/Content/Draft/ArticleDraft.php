<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Draft;

use RoboJackSparrow\Content\Dto\Faq;

final class ArticleDraft
{
    /**
     * @param array<int, array{title: string, level: int, html: string}> $sections
     * @param Faq[] $faqs
     * @param string[] $focusKeywords
     */
    public function __construct(
        private string $title,
        private string $metaDescription,
        private array $sections,
        private array $faqs,
        private array $focusKeywords,
        private ?string $imagePrompt
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMetaDescription(): string
    {
        return $this->metaDescription;
    }

    /**
     * @return array<int, array{title: string, level: int, html: string}>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * @return Faq[]
     */
    public function getFaqs(): array
    {
        return $this->faqs;
    }

    /**
     * @return string[]
     */
    public function getFocusKeywords(): array
    {
        return $this->focusKeywords;
    }

    public function getImagePrompt(): ?string
    {
        return $this->imagePrompt;
    }
}
