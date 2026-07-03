<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Draft;

use RoboJackSparrow\Content\ContentException;
use RoboJackSparrow\Content\Dto\Faq;
use RoboJackSparrow\Content\JsonResponseParser;

/**
 * Parses the single consolidated LLM response (title, sections already
 * containing their own HTML body, FAQs, focus keywords, image prompt) into
 * an ArticleDraft. Replaces the old separate outline-parsing + per-section
 * generation + FAQ-parsing steps now that all of it comes back from one
 * LLM call - see ContentEngine.
 */
class ArticleDraftParser
{
    /**
     * @throws ContentException When the response cannot be parsed into a usable draft.
     */
    public function parse(string $rawResponse): ArticleDraft
    {
        $data = JsonResponseParser::decode($rawResponse);

        if ($data === null) {
            throw new ContentException('Could not parse article JSON from LLM response');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ContentException('LLM article response is missing a title');
        }

        $sections = $this->parseSections((array) ($data['sections'] ?? []));
        if ($sections === []) {
            throw new ContentException('LLM article response has no valid sections');
        }

        return new ArticleDraft(
            title: $title,
            metaDescription: trim((string) ($data['meta_description'] ?? '')),
            sections: $sections,
            faqs: $this->parseFaqs((array) ($data['faqs'] ?? [])),
            focusKeywords: $this->parseFocusKeywords((array) ($data['focus_keywords'] ?? [])),
            imagePrompt: isset($data['image_prompt']) && trim((string) $data['image_prompt']) !== ''
                ? trim((string) $data['image_prompt'])
                : null
        );
    }

    /**
     * @return array<int, array{title: string, level: int, html: string}>
     */
    private function parseSections(array $rawSections): array
    {
        $sections = [];

        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionTitle = trim((string) ($section['title'] ?? ''));
            $html = trim((string) ($section['html'] ?? ''));

            if ($sectionTitle === '' || $html === '') {
                continue;
            }

            $sections[] = [
                'title' => $sectionTitle,
                // H1 is reserved for the post title itself, so section
                // headings are clamped to H2-H6.
                'level' => max(2, min(6, (int) ($section['level'] ?? 2))),
                'html'  => $html,
            ];
        }

        return $sections;
    }

    /**
     * @return Faq[]
     */
    private function parseFaqs(array $rawFaqs): array
    {
        $faqs = [];

        foreach ($rawFaqs as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $faqs[] = new Faq($question, $answer);
        }

        return $faqs;
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
