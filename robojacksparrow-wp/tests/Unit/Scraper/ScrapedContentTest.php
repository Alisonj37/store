<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Scraper;

use RoboJackSparrow\Scraper\Dto\ScrapedContent;
use RoboJackSparrow\Tests\TestCase;

final class ScrapedContentTest extends TestCase
{
    public function testExtractOutlineIgnoresMismatchedHeadingTags(): void
    {
        $content = new ScrapedContent(
            url: 'https://example.com/x',
            title: 'X',
            text: 'body',
            html: '<h1>Main Title</h1><p>intro</p><h2>Sub One</h2><h2>Sub <b>Two</b></h2><h3 class="x">Deep</h3><h2>Mismatched</h3>'
        );

        $this->assertSame([
            ['level' => 1, 'text' => 'Main Title'],
            ['level' => 2, 'text' => 'Sub One'],
            ['level' => 2, 'text' => 'Sub Two'],
            ['level' => 3, 'text' => 'Deep'],
        ], $content->extractOutline());
    }

    public function testExtractOutlineReturnsEmptyArrayWithoutHtml(): void
    {
        $content = new ScrapedContent(url: 'x', title: 'x', text: 'x', html: null);

        $this->assertSame([], $content->extractOutline());
    }
}
