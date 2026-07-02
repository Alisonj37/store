<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Dto;

final class Outline
{
    /**
     * @param array<int, array{title: string, level: int}> $sections
     * @param string[] $focusKeywords
     */
    public function __construct(
        private string $title,
        private string $metaDescription,
        private array $sections,
        private array $focusKeywords,
        private ?string $imagePrompt = null
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
     * @return array<int, array{title: string, level: int}>
     */
    public function getSections(): array
    {
        return $this->sections;
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

    /**
     * Renders the outline as a Markdown-style heading list, used as context
     * in the section-generation prompt so the LLM knows the full structure.
     */
    public function toString(): string
    {
        $lines = [];

        foreach ($this->sections as $section) {
            $level = max(1, (int) $section['level']);
            $lines[] = str_repeat('#', $level) . ' ' . $section['title'];
        }

        return implode("\n", $lines);
    }
}
