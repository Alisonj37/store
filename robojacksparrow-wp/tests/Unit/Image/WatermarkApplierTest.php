<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Image;

use RoboJackSparrow\Image\Processor\WatermarkApplier;
use RoboJackSparrow\Tests\TestCase;

final class WatermarkApplierTest extends TestCase
{
    private string $logoPath;
    private string $overlayPath;
    private string $baseBytes;

    protected function setUp(): void
    {
        parent::setUp();

        $base = imagecreatetruecolor(200, 200);
        imagefill($base, 0, 0, imagecolorallocate($base, 200, 30, 30));
        ob_start();
        imagepng($base);
        $this->baseBytes = (string) ob_get_clean();
        imagedestroy($base);

        $logo = imagecreatetruecolor(40, 40);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 0, 255, 0));
        $this->logoPath = sys_get_temp_dir() . '/rjs_test_logo_' . uniqid() . '.png';
        imagepng($logo, $this->logoPath);
        imagedestroy($logo);

        $overlay = imagecreatetruecolor(200, 200);
        imagefill($overlay, 0, 0, imagecolorallocate($overlay, 0, 0, 255));
        $this->overlayPath = sys_get_temp_dir() . '/rjs_test_overlay_' . uniqid() . '.png';
        imagepng($overlay, $this->overlayPath);
        imagedestroy($overlay);
    }

    protected function tearDown(): void
    {
        @unlink($this->logoPath);
        @unlink($this->overlayPath);
        parent::tearDown();
    }

    public function testLogoIsCompositedAtBottomRightLeavingRestOfImageUntouched(): void
    {
        $applier = new WatermarkApplier();
        $withLogo = $applier->apply($this->baseBytes, $this->logoPath, null, ['logo_opacity' => 100]);

        $img = imagecreatefromstring($withLogo);
        $this->assertNotFalse($img);
        $this->assertSame(200, imagesx($img));

        // Logo is 40x40 at bottom-right with a 20px margin on a 200x200
        // image -> spans x/y in [140, 180). Midpoint (160, 160) must be green.
        $corner = imagecolorsforindex($img, imagecolorat($img, 160, 160));
        $this->assertLessThan(50, $corner['red']);
        $this->assertGreaterThan(200, $corner['green']);

        // Far corner must remain the original red.
        $far = imagecolorsforindex($img, imagecolorat($img, 5, 5));
        $this->assertGreaterThan(150, $far['red']);
        $this->assertLessThan(60, $far['green']);

        imagedestroy($img);
    }

    public function testOverlayBlendsTowardItsColorAtPartialOpacity(): void
    {
        $applier = new WatermarkApplier();
        $withOverlay = $applier->apply($this->baseBytes, null, $this->overlayPath, ['overlay_opacity' => 50]);

        $img = imagecreatefromstring($withOverlay);
        $this->assertNotFalse($img);

        $blended = imagecolorsforindex($img, imagecolorat($img, 100, 100));
        // Started at (200,30,30), blended 50% toward (0,0,255).
        $this->assertGreaterThan(50, $blended['red']);
        $this->assertLessThan(150, $blended['red']);
        $this->assertGreaterThan(100, $blended['blue']);

        imagedestroy($img);
    }

    public function testCombiningLogoAndOverlayDoesNotError(): void
    {
        $applier = new WatermarkApplier();
        $both = $applier->apply($this->baseBytes, $this->logoPath, $this->overlayPath, ['logo_opacity' => 90, 'overlay_opacity' => 25]);

        $img = imagecreatefromstring($both);
        $this->assertNotFalse($img);
        $this->assertSame(200, imagesx($img));
        imagedestroy($img);
    }

    public function testWithoutAnyWatermarkAssetsStillReturnsAValidImage(): void
    {
        $applier = new WatermarkApplier();
        $img = imagecreatefromstring($applier->apply($this->baseBytes));

        $this->assertNotFalse($img);
        $this->assertSame(200, imagesx($img));
        imagedestroy($img);
    }
}
