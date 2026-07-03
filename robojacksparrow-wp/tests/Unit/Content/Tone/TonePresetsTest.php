<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Content\Tone;

use RoboJackSparrow\Content\Tone\TonePresets;
use RoboJackSparrow\Tests\TestCase;

final class TonePresetsTest extends TestCase
{
    public function testChoicesReturnsAllPresetsAsKeyLabelPairs(): void
    {
        $choices = TonePresets::choices();

        $this->assertArrayHasKey('geral', $choices);
        $this->assertArrayHasKey('afiliado', $choices);
        $this->assertArrayHasKey('noticia', $choices);
        $this->assertArrayHasKey('tecnologia', $choices);
        $this->assertIsString($choices['afiliado']);
    }

    public function testIsValidRecognizesKnownAndUnknownPresets(): void
    {
        $this->assertTrue(TonePresets::isValid('afiliado'));
        $this->assertFalse(TonePresets::isValid('not-a-real-preset'));
    }

    public function testInstructionsForFallsBackToDefaultOnUnknownPreset(): void
    {
        $default = TonePresets::instructionsFor(TonePresets::defaultPreset());

        $this->assertSame($default, TonePresets::instructionsFor('not-a-real-preset'));
    }

    public function testEachPresetHasNonEmptyInstructions(): void
    {
        foreach (array_keys(TonePresets::choices()) as $key) {
            $this->assertNotSame('', trim(TonePresets::instructionsFor($key)), "preset '{$key}' must have instructions");
        }
    }
}
