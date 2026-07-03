<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image\Processor;

use GdImage;
use RoboJackSparrow\Image\ImageException;

/**
 * Aplica logo (canto, com margem/opacidade configuraveis) e/ou overlay
 * (imagem em tela cheia, tipicamente semi-transparente) sobre uma imagem,
 * usando GD - sem dependencia de Imagick, para compatibilidade maxima com
 * hospedagem compartilhada.
 */
class WatermarkApplier
{
    private const DEFAULT_MARGIN = 20;
    private const DEFAULT_LOGO_OPACITY = 80;
    private const DEFAULT_OVERLAY_OPACITY = 30;

    /**
     * @param array{logo_position?: string, logo_margin?: int, logo_opacity?: int, overlay_opacity?: int} $options
     */
    public function apply(string $imageData, ?string $logoPath = null, ?string $overlayPath = null, array $options = []): string
    {
        if (!extension_loaded('gd')) {
            throw new ImageException('The GD extension is required to apply watermarks');
        }

        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            throw new ImageException('Could not read image data to apply watermark');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        if ($overlayPath !== null && is_file($overlayPath)) {
            $this->applyOverlay($image, $overlayPath, (int) ($options['overlay_opacity'] ?? self::DEFAULT_OVERLAY_OPACITY));
        }

        if ($logoPath !== null && is_file($logoPath)) {
            $this->applyLogo(
                $image,
                $logoPath,
                (string) ($options['logo_position'] ?? 'bottom-right'),
                (int) ($options['logo_margin'] ?? self::DEFAULT_MARGIN),
                (int) ($options['logo_opacity'] ?? self::DEFAULT_LOGO_OPACITY)
            );
        }

        $output = $this->encode($image);
        imagedestroy($image);

        return $output;
    }

    /**
     * Overlays span the full canvas (potentially millions of pixels), so
     * this uses GD's native imagecopymerge() (C-speed) rather than the
     * manual per-pixel blend used for logos. imagecopymerge()'s well-known
     * alpha bug only manifests when the SOURCE image itself carries partial
     * transparency; overlay assets are expected to be fully opaque images
     * (the semi-transparency here comes entirely from the $opacity blend
     * percentage, not from the asset's own alpha channel), so the bug does
     * not apply and correctness is unaffected. On shared hosting, a
     * pure-PHP pixel loop over a full 1080p+ image can by itself consume
     * most of a Worker job's execution-time budget; this keeps the cost to
     * a handful of native GD calls instead of ~8M PHP-level ones.
     */
    private function applyOverlay(GdImage $image, string $overlayPath, int $opacity): void
    {
        $overlay = $this->loadImage($overlayPath);
        $width = imagesx($image);
        $height = imagesy($image);

        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $overlay, 0, 0, 0, 0, $width, $height, imagesx($overlay), imagesy($overlay));
        imagedestroy($overlay);

        imagecopymerge($image, $resized, 0, 0, 0, 0, $width, $height, max(0, min(100, $opacity)));
        imagedestroy($resized);
    }

    private function applyLogo(GdImage $image, string $logoPath, string $position, int $margin, int $opacity): void
    {
        $logo = $this->loadImage($logoPath);
        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);

        [$x, $y] = $this->resolvePosition($position, $imageWidth, $imageHeight, $logoWidth, $logoHeight, $margin);

        $this->blendWithOpacity($image, $logo, $x, $y, $opacity);
        imagedestroy($logo);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolvePosition(string $position, int $imageWidth, int $imageHeight, int $logoWidth, int $logoHeight, int $margin): array
    {
        return match ($position) {
            'top-left'    => [$margin, $margin],
            'top-right'   => [$imageWidth - $logoWidth - $margin, $margin],
            'bottom-left' => [$margin, $imageHeight - $logoHeight - $margin],
            'center'      => [(int) (($imageWidth - $logoWidth) / 2), (int) (($imageHeight - $logoHeight) / 2)],
            default       => [$imageWidth - $logoWidth - $margin, $imageHeight - $logoHeight - $margin], // bottom-right
        };
    }

    private function loadImage(string $path): GdImage
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new ImageException("Could not read watermark asset: {$path}");
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new ImageException("Could not decode watermark asset: {$path}");
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * GD's imagecopymerge() does not handle alpha channels correctly (a
     * well-known bug that produces black artifacts on transparent PNGs), so
     * opacity blending is done manually, pixel by pixel, for anything below
     * full opacity. At full opacity a plain imagecopy() is used, which is
     * both faster and preserves the source's own alpha channel correctly.
     */
    private function blendWithOpacity(GdImage $destination, GdImage $source, int $destX, int $destY, int $opacity): void
    {
        $opacity = max(0, min(100, $opacity));
        $width = imagesx($source);
        $height = imagesy($source);

        if ($opacity >= 100) {
            imagecopy($destination, $source, $destX, $destY, 0, 0, $width, $height);

            return;
        }

        $destWidth = imagesx($destination);
        $destHeight = imagesy($destination);

        for ($y = 0; $y < $height; $y++) {
            $destPixelY = $destY + $y;
            if ($destPixelY < 0 || $destPixelY >= $destHeight) {
                continue;
            }

            for ($x = 0; $x < $width; $x++) {
                $destPixelX = $destX + $x;
                if ($destPixelX < 0 || $destPixelX >= $destWidth) {
                    continue;
                }

                $sourceColor = imagecolorat($source, $x, $y);
                $sourceAlpha = ($sourceColor >> 24) & 0x7F; // 0 (opaque) .. 127 (fully transparent)
                $fraction = (1 - $sourceAlpha / 127) * ($opacity / 100);

                if ($fraction <= 0) {
                    continue;
                }

                $destColor = imagecolorat($destination, $destPixelX, $destPixelY);

                $red = (int) round((($sourceColor >> 16) & 0xFF) * $fraction + (($destColor >> 16) & 0xFF) * (1 - $fraction));
                $green = (int) round((($sourceColor >> 8) & 0xFF) * $fraction + (($destColor >> 8) & 0xFF) * (1 - $fraction));
                $blue = (int) round(($sourceColor & 0xFF) * $fraction + ($destColor & 0xFF) * (1 - $fraction));

                $blended = imagecolorallocatealpha($destination, $red, $green, $blue, 0);
                imagesetpixel($destination, $destPixelX, $destPixelY, $blended);
            }
        }
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 6);
        $data = ob_get_clean();

        if ($data === false) {
            throw new ImageException('Failed to encode watermarked image');
        }

        return $data;
    }
}
