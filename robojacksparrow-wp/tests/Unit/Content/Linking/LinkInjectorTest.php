<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content\Linking;

use RoboJackSparrow\Content\Linking\LinkInjector;
use RoboJackSparrow\Tests\TestCase;

final class LinkInjectorTest extends TestCase
{
    public function testAppendsLinksForRelatedArticlesWithBothFieldsPresent(): void
    {
        $injector = new LinkInjector();

        $related = [
            (object) ['source_title' => 'Artigo A', 'wordpress_post_url' => 'https://example.test/?p=1'],
            (object) ['source_title' => 'Artigo B', 'wordpress_post_url' => 'https://example.test/?p=2'],
        ];

        $html = $injector->appendInternalLinks('<p>Conteudo</p>', $related);

        $this->assertStringContainsString('<h2>Leia tambem</h2>', $html);
        $this->assertStringContainsString('<a href="https://example.test/?p=1">Artigo A</a>', $html);
        $this->assertStringContainsString('<a href="https://example.test/?p=2">Artigo B</a>', $html);
        $this->assertStringStartsWith('<p>Conteudo</p>', $html);
    }

    public function testReturnsOriginalHtmlUnchangedWhenNoRelatedArticles(): void
    {
        $injector = new LinkInjector();

        $html = $injector->appendInternalLinks('<p>Conteudo</p>', []);

        $this->assertSame('<p>Conteudo</p>', $html);
    }

    public function testSkipsRowsMissingTitleOrUrl(): void
    {
        $injector = new LinkInjector();

        $related = [
            (object) ['source_title' => '', 'wordpress_post_url' => 'https://example.test/?p=1'],
            (object) ['source_title' => 'Sem URL', 'wordpress_post_url' => null],
        ];

        $html = $injector->appendInternalLinks('<p>Conteudo</p>', $related);

        $this->assertSame('<p>Conteudo</p>', $html, 'rows with a blank title or URL contribute no link and must not leave an empty section behind');
    }
}
