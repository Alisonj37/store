<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Faq;

use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\LLMRouter;
use RoboJackSparrow\Content\Dto\Faq;
use RoboJackSparrow\Content\JsonResponseParser;
use RoboJackSparrow\Content\Prompts\PromptLibrary;

class FaqExtractor
{
    private int $lastTokensUsed = 0;

    public function __construct(
        private LLMRouter $llm,
        private PromptLibrary $prompts
    ) {
    }

    /**
     * @return Faq[]
     */
    public function extract(string $content, ?string $preferredProvider = null, ?string $modelOverride = null): array
    {
        if (trim($content) === '') {
            $this->lastTokensUsed = 0;

            return [];
        }

        $prompt = $this->prompts->get('faq_extraction', ['content' => $content]);

        $response = $this->llm->route(new LLMRequest(
            prompt: $prompt,
            model: $modelOverride,
            isJsonMode: true,
            maxTokens: 1500,
            temperature: 0.5,
            preferredProvider: $preferredProvider
        ));

        $this->lastTokensUsed = $response->getTokensUsed();

        return $this->parse($response->getContent());
    }

    /**
     * Tokens spent by the most recent extract() call - ContentEngine adds
     * this into its own running total, since extract() itself only returns
     * the parsed Faq[] (unchanged contract for existing callers).
     */
    public function getLastTokensUsed(): int
    {
        return $this->lastTokensUsed;
    }

    /**
     * Builds the schema.org FAQPage JSON-LD block from extracted FAQs.
     *
     * @param Faq[] $faqs
     */
    public function toSchema(array $faqs): array
    {
        if ($faqs === []) {
            return [];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(
                static fn (Faq $faq) => [
                    '@type'          => 'Question',
                    'name'           => $faq->getQuestion(),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $faq->getAnswer(),
                    ],
                ],
                $faqs
            ),
        ];
    }

    /**
     * @return Faq[]
     */
    private function parse(string $rawResponse): array
    {
        $data = JsonResponseParser::decode($rawResponse);

        if ($data === null) {
            return [];
        }

        // Accept either a bare JSON array or {"faqs": [...]}.
        $items = array_is_list($data) ? $data : (array) ($data['faqs'] ?? []);

        $faqs = [];
        foreach ($items as $item) {
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
}
