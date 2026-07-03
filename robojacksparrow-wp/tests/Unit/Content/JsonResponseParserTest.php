<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content;

use RoboJackSparrow\Content\JsonResponseParser;
use RoboJackSparrow\Tests\TestCase;

final class JsonResponseParserTest extends TestCase
{
    public function testDecodesAWellFormedJsonObject(): void
    {
        $data = JsonResponseParser::decode('{"title":"X","sections":[{"title":"A","html":"<p>a</p>"}]}');

        $this->assertSame('X', $data['title']);
    }

    public function testStripsMarkdownCodeFences(): void
    {
        $data = JsonResponseParser::decode("```json\n{\"title\":\"X\"}\n```");

        $this->assertSame('X', $data['title']);
    }

    /**
     * Simulates the single-call architecture's main new failure mode: the
     * provider hits its max_tokens ceiling mid-response and the JSON is cut
     * off partway through a later section. Whatever complete sections/faqs
     * came before the cut-off point must still be salvageable instead of
     * losing the entire article.
     */
    public function testRepairsJsonTruncatedMidSection(): void
    {
        $truncated = '{"title":"Titulo Completo","meta_description":"Uma meta.",'
            . '"sections":[{"title":"Intro","level":2,"html":"<p>Corpo intro.</p>"},'
            . '{"title":"Meio","level":2,"html":"<p>Corpo meio.</p>"},'
            . '{"title":"Isso foi cortado no meio da fra';

        $data = JsonResponseParser::decode($truncated);

        $this->assertIsArray($data);
        $this->assertSame('Titulo Completo', $data['title']);
        $this->assertCount(2, $data['sections'], 'the two complete sections must survive even though the third was cut off');
        $this->assertSame('Meio', $data['sections'][1]['title']);
    }

    public function testRepairsJsonTruncatedRightAfterASectionsArrayCloses(): void
    {
        $truncated = '{"title":"X","sections":[{"title":"A","level":2,"html":"<p>a</p>"}],"faqs":[{"question":"Q1?","answer":"resposta cortada no me';

        $data = JsonResponseParser::decode($truncated);

        $this->assertIsArray($data);
        $this->assertSame('X', $data['title']);
        $this->assertCount(1, $data['sections']);
    }

    public function testReturnsNullForCompletelyUnstructuredGarbage(): void
    {
        $this->assertNull(JsonResponseParser::decode('not json at all, no brackets whatsoever'));
    }

    public function testReturnsNullWhenNothingCanBeSalvaged(): void
    {
        // A single opening brace with no complete nested value at all.
        $this->assertNull(JsonResponseParser::decode('{"title":"cut off right after the key opens": "unterminated'));
    }
}
