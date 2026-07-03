<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content\Draft;

use RoboJackSparrow\Content\ContentException;
use RoboJackSparrow\Content\Draft\ArticleDraftParser;
use RoboJackSparrow\Tests\TestCase;

final class ArticleDraftParserTest extends TestCase
{
    public function testParsesAFullResponseIntoADraft(): void
    {
        $json = json_encode([
            'title'            => 'Titulo do Artigo',
            'meta_description' => 'Uma descricao curta.',
            'sections'         => [
                ['title' => 'Introducao', 'level' => 2, 'html' => '<p>Corpo da introducao.</p>'],
                ['title' => 'Detalhes', 'level' => 3, 'html' => '<p>Corpo dos detalhes.</p>'],
            ],
            'faqs' => [
                ['question' => 'Pergunta 1?', 'answer' => 'Resposta 1.'],
            ],
            'focus_keywords' => ['robo', 'automacao'],
            'image_prompt'   => 'a robot',
        ]);

        $draft = (new ArticleDraftParser())->parse($json);

        $this->assertSame('Titulo do Artigo', $draft->getTitle());
        $this->assertSame('Uma descricao curta.', $draft->getMetaDescription());
        $this->assertCount(2, $draft->getSections());
        $this->assertSame('Detalhes', $draft->getSections()[1]['title']);
        $this->assertSame(3, $draft->getSections()[1]['level']);
        $this->assertCount(1, $draft->getFaqs());
        $this->assertSame('Pergunta 1?', $draft->getFaqs()[0]->getQuestion());
        $this->assertSame(['robo', 'automacao'], $draft->getFocusKeywords());
        $this->assertSame('a robot', $draft->getImagePrompt());
    }

    public function testClampsSectionLevelToH2ThroughH6(): void
    {
        $json = json_encode([
            'title'    => 'X',
            'sections' => [
                ['title' => 'A', 'level' => 1, 'html' => '<p>a</p>'],
                ['title' => 'B', 'level' => 9, 'html' => '<p>b</p>'],
            ],
        ]);

        $draft = (new ArticleDraftParser())->parse($json);

        $this->assertSame(2, $draft->getSections()[0]['level'], 'H1 is reserved for the post title');
        $this->assertSame(6, $draft->getSections()[1]['level']);
    }

    public function testSkipsSectionsMissingTitleOrHtml(): void
    {
        $json = json_encode([
            'title'    => 'X',
            'sections' => [
                ['title' => '', 'level' => 2, 'html' => '<p>sem titulo</p>'],
                ['title' => 'Sem corpo', 'level' => 2, 'html' => ''],
                ['title' => 'Valida', 'level' => 2, 'html' => '<p>ok</p>'],
            ],
        ]);

        $draft = (new ArticleDraftParser())->parse($json);

        $this->assertCount(1, $draft->getSections());
        $this->assertSame('Valida', $draft->getSections()[0]['title']);
    }

    public function testThrowsWhenResponseIsNotValidJson(): void
    {
        $this->expectException(ContentException::class);
        (new ArticleDraftParser())->parse('not json at all');
    }

    public function testThrowsWhenTitleIsMissing(): void
    {
        $this->expectException(ContentException::class);
        (new ArticleDraftParser())->parse(json_encode(['sections' => [['title' => 'A', 'html' => '<p>a</p>']]]));
    }

    public function testThrowsWhenNoValidSectionsSurvive(): void
    {
        $this->expectException(ContentException::class);
        (new ArticleDraftParser())->parse(json_encode(['title' => 'X', 'sections' => [['title' => '', 'html' => '']]]));
    }

    public function testParsesSuccessfullyEvenWhenLastSectionWasCutOffByTruncation(): void
    {
        $truncated = '{"title":"Titulo","sections":['
            . '{"title":"Intro","level":2,"html":"<p>ok</p>"},'
            . '{"title":"Cortada no me';

        $draft = (new ArticleDraftParser())->parse($truncated);

        $this->assertSame('Titulo', $draft->getTitle());
        $this->assertCount(1, $draft->getSections(), 'the cut-off trailing section must be dropped, not crash the whole parse');
    }

    public function testStripsMarkdownCodeFencesBeforeParsing(): void
    {
        $json = "```json\n" . json_encode([
            'title'    => 'X',
            'sections' => [['title' => 'A', 'level' => 2, 'html' => '<p>a</p>']],
        ]) . "\n```";

        $draft = (new ArticleDraftParser())->parse($json);

        $this->assertSame('X', $draft->getTitle());
    }
}
