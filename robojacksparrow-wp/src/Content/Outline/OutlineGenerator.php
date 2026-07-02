<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Outline;

use RoboJackSparrow\Content\ContentException;
use RoboJackSparrow\Content\Dto\Outline;
use RoboJackSparrow\Content\JsonResponseParser;

class OutlineGenerator
{
    /**
     * Parses the LLM's outline-generation JSON response into an Outline DTO.
     * Expected shape:
     *   {
     *     "title": "...",
     *     "meta_description": "...",
     *     "sections": [{"title": "...", "level": 2}, ...],
     *     "focus_keywords": ["...", ...],
     *     "image_prompt": "..."
     *   }
     *
     * @throws ContentException When the response cannot be parsed into a usable outline.
     */
    public function parse(string $rawResponse): Outline
    {
        $data = JsonResponseParser::decode($rawResponse);

        if ($data === null) {
            throw new ContentException('Could not parse outline JSON from LLM response');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ContentException('LLM outline response is missing a title');
        }

        $sections = $this->parseSections((array) ($data['sections'] ?? []));
        if ($sections === []) {
            throw new ContentException('LLM outline response has no valid sections');
        }

        return new Outline(
            title: $title,
            metaDescription: trim((string) ($data['meta_description'] ?? '')),
            sections: $sections,
            focusKeywords: $this->parseFocusKeywords((array) ($data['focus_keywords'] ?? [])),
            imagePrompt: isset($data['image_prompt']) && trim((string) $data['image_prompt']) !== ''
                ? trim((string) $data['image_prompt'])
                : null
        );
    }

    /**
     * @return array<int, array{title: string, level: int}>
     */
    private function parseSections(array $rawSections): array
    {
        $sections = [];

        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionTitle = trim((string) ($section['title'] ?? ''));
            if ($sectionTitle === '') {
                continue;
            }

            $sections[] = [
                'title' => $sectionTitle,
                // H1 is reserved for the post title itself, so section
                // headings are clamped to H2-H6.
                'level' => max(2, min(6, (int) ($section['level'] ?? 2))),
            ];
        }

        return $sections;
    }

    /**
     * @return string[]
     */
    private function parseFocusKeywords(array $raw): array
    {
        $keywords = array_map(static fn ($keyword) => trim((string) $keyword), $raw);

        return array_values(array_filter($keywords, static fn (string $keyword) => $keyword !== ''));
    }
}
