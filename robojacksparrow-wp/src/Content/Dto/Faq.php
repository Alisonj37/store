<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Dto;

final class Faq
{
    public function __construct(
        private string $question,
        private string $answer
    ) {
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    /**
     * Builds the schema.org FAQPage JSON-LD block from a set of FAQs.
     *
     * @param Faq[] $faqs
     */
    public static function schemaFor(array $faqs): array
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
}
