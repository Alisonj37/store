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

    public function testNewsPresetMustStayUnderOneThousandWords(): void
    {
        $bounds = TonePresets::wordCountBoundsFor('noticia');

        $this->assertLessThan(1000, $bounds['max']);
        $this->assertGreaterThan(0, $bounds['min']);
        $this->assertLessThanOrEqual($bounds['max'], $bounds['default']);
        $this->assertGreaterThanOrEqual($bounds['min'], $bounds['default']);
    }

    public function testEveryNonNewsPresetMustStayBetween1500And2500Words(): void
    {
        foreach (array_keys(TonePresets::choices()) as $key) {
            if ($key === 'noticia') {
                continue;
            }

            $bounds = TonePresets::wordCountBoundsFor($key);

            $this->assertSame(1500, $bounds['min'], "preset '{$key}' must have a 1500-word floor");
            $this->assertSame(2500, $bounds['max'], "preset '{$key}' must have a 2500-word ceiling");
        }
    }
}
