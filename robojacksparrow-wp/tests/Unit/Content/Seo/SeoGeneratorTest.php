<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content\Seo;

use RoboJackSparrow\Content\Seo\SeoGenerator;
use RoboJackSparrow\Tests\TestCase;

final class SeoGeneratorTest extends TestCase
{
    public function testTitleAndDescriptionAreUsedAsIsWhenTheyAlreadyContainTheFocusKeyword(): void
    {
        $seo = new SeoGenerator();

        $data = $seo->generate(
            'Melhor cafeteira eletrica 2026',
            'Veja o ranking da melhor cafeteira eletrica para comprar em 2026.',
            '<p>conteudo</p>',
            ['cafeteira eletrica']
        );

        $this->assertSame('Melhor cafeteira eletrica 2026', $data->getTitle());
        $this->assertStringContainsString('cafeteira eletrica', $data->getDescription());
    }

    public function testAppendsTheFocusKeywordToTheTitleWhenMissingAndThereIsRoom(): void
    {
        $seo = new SeoGenerator();

        $data = $seo->generate('Guia rapido', 'Uma descricao qualquer sem a palavra chave.', '<p>x</p>', ['robotica industrial']);

        $this->assertStringContainsString('robotica industrial', $data->getTitle());
        $this->assertLessThanOrEqual(60, mb_strlen($data->getTitle()));
    }

    public function testLeadsWithTheKeywordWhenThereIsNoRoomToAppendIt(): void
    {
        $seo = new SeoGenerator();

        $longTitle = str_repeat('Um titulo bem longo que ocupa quase todo o espaco disponivel ', 2);
        $data = $seo->generate($longTitle, 'desc', '<p>x</p>', ['palavra-chave-foco']);

        $this->assertStringStartsWith('palavra-chave-foco', $data->getTitle());
        $this->assertLessThanOrEqual(60, mb_strlen($data->getTitle()));
    }

    public function testNoFocusKeywordLeavesTitleAndDescriptionUntouched(): void
    {
        $seo = new SeoGenerator();

        $data = $seo->generate('Titulo normal', 'Descricao normal.', '<p>x</p>', []);

        $this->assertSame('Titulo normal', $data->getTitle());
        $this->assertSame('Descricao normal.', $data->getDescription());
    }

    public function testMetaDescriptionFallsBackToPlainTextWhenBlank(): void
    {
        $seo = new SeoGenerator();

        $data = $seo->generate('T', '', '<p>Conteudo do artigo em texto puro.</p>', []);

        $this->assertStringContainsString('Conteudo do artigo em texto puro.', $data->getDescription());
    }
}
