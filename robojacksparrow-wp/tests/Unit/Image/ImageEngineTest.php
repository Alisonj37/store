<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Image;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\ImageEngine;
use RoboJackSparrow\Image\ImageException;
use RoboJackSparrow\Image\Providers\KeiIaProvider;
use RoboJackSparrow\Image\Providers\PexelsProvider;
use RoboJackSparrow\Image\Providers\PixabayProvider;
use RoboJackSparrow\Image\Providers\ReplicateProvider;
use RoboJackSparrow\Image\Providers\UnsplashProvider;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class ImageEngineTest extends TestCase
{
    private static function tinyPng(): string
    {
        $img = imagecreatetruecolor(4, 4);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 200));
        ob_start();
        imagepng($img);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    public function testKeiIaProviderDecodesBase64Image(): void
    {
        $png = self::tinyPng();
        HttpFixtures::set('POST', 'https://api.kei.ia/v1/images/generations', [
            'response' => ['code' => 200],
            'body'     => json_encode(['data' => [['b64_json' => base64_encode($png)]]]),
        ]);

        $result = (new KeiIaProvider('kei-key'))->generate(new ImageRequest('a robot'));

        $this->assertSame('kei_ia', $result->getProvider());
        $this->assertSame($png, $result->getBinaryData());
    }

    public function testReplicateProviderUsesSynchronousPreferWaitResponse(): void
    {
        $png = self::tinyPng();
        HttpFixtures::set('POST', 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions', [
            'response' => ['code' => 201],
            'body'     => json_encode(['status' => 'succeeded', 'output' => ['https://replicate.delivery/out.png']]),
        ]);
        HttpFixtures::set('GET', 'https://replicate.delivery/out.png', ['response' => ['code' => 200], 'body' => $png]);

        $result = (new ReplicateProvider('r8-key'))->generate(new ImageRequest('a robot'));

        $this->assertSame('replicate', $result->getProvider());
        $this->assertSame($png, $result->getBinaryData());
    }

    public function testReplicateProviderThrowsInsteadOfHangingWhenNoPollUrlIsAvailable(): void
    {
        HttpFixtures::set('POST', 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions', [
            'response' => ['code' => 201],
            'body'     => json_encode(['status' => 'processing']),
        ]);

        $this->expectException(ImageException::class);
        $this->expectExceptionMessageMatches('/did not succeed/');
        (new ReplicateProvider('r8-key'))->generate(new ImageRequest('a robot'));
    }

    public function testUnsplashProviderMapsAttribution(): void
    {
        $png = self::tinyPng();
        HttpFixtures::setPrefix('GET', 'https://api.unsplash.com/photos/random', fn () => [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'urls'  => ['regular' => 'https://images.unsplash.com/photo-abc'],
                'user'  => ['name' => 'Jane Photographer', 'links' => ['html' => 'https://unsplash.com/@jane']],
                'links' => ['html' => 'https://unsplash.com/photos/abc', 'download_location' => 'https://api.unsplash.com/photos/abc/download'],
            ]),
        ]);
        HttpFixtures::set('GET', 'https://images.unsplash.com/photo-abc', ['response' => ['code' => 200], 'body' => $png]);
        HttpFixtures::set('GET', 'https://api.unsplash.com/photos/abc/download', ['response' => ['code' => 200], 'body' => '']);

        $result = (new UnsplashProvider('unsplash-key'))->generate(new ImageRequest('a robot'));

        $this->assertSame('Jane Photographer', $result->getAttribution()->getAuthorName());
        $this->assertSame('Foto por Jane Photographer / Unsplash', $result->getAttribution()->toCreditText());
    }

    public function testCascadeFallsThroughToTheFirstWorkingProvider(): void
    {
        $png = self::tinyPng();

        HttpFixtures::set('POST', 'https://api.kei.ia/v1/images/generations', ['response' => ['code' => 500], 'body' => '{}']);
        HttpFixtures::set('POST', 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions', ['response' => ['code' => 401], 'body' => '{}']);
        HttpFixtures::setPrefix('GET', 'https://api.pexels.com/v1/search', fn () => [
            'response' => ['code' => 200],
            'body'     => json_encode(['photos' => [['src' => ['large' => 'https://images.pexels.com/p.jpg'], 'photographer' => 'John', 'photographer_url' => 'https://pexels.com/@john', 'url' => 'https://pexels.com/p']]]),
        ]);
        HttpFixtures::set('GET', 'https://images.pexels.com/p.jpg', ['response' => ['code' => 200], 'body' => $png]);

        $engine = new ImageEngine([
            new KeiIaProvider('k'),
            new ReplicateProvider('r'),
            new PexelsProvider('pex-key'),
            new PixabayProvider('pix-key'),
        ], new Logger());

        $result = $engine->generate(new ImageRequest('a robot'));

        $this->assertSame('pexels', $result->getProvider());
    }

    public function testPreferredProviderIsTriedFirstEvenOutOfCascadeOrder(): void
    {
        $png = self::tinyPng();

        HttpFixtures::setPrefix('GET', 'https://api.pexels.com/v1/search', fn () => [
            'response' => ['code' => 200],
            'body'     => json_encode(['photos' => [['src' => ['large' => 'https://images.pexels.com/p.jpg'], 'photographer' => 'John', 'photographer_url' => 'https://pexels.com/@john', 'url' => 'https://pexels.com/p']]]),
        ]);
        HttpFixtures::set('GET', 'https://images.pexels.com/p.jpg', ['response' => ['code' => 200], 'body' => $png]);

        // kei.ia is first in cascade order but unconfigured (would fail);
        // an explicit 'pexels' override must be tried before it anyway.
        $engine = new ImageEngine([
            new KeiIaProvider(''),
            new PexelsProvider('pex-key'),
        ], new Logger());

        $result = $engine->generate(new ImageRequest('a robot'), 'pexels');

        $this->assertSame('pexels', $result->getProvider());
    }

    public function testGlobalPreferredProviderSettingIsUsedWhenNoOverrideGiven(): void
    {
        $png = self::tinyPng();

        HttpFixtures::setPrefix('GET', 'https://api.pexels.com/v1/search', fn () => [
            'response' => ['code' => 200],
            'body'     => json_encode(['photos' => [['src' => ['large' => 'https://images.pexels.com/p.jpg'], 'photographer' => 'John', 'photographer_url' => 'https://pexels.com/@john', 'url' => 'https://pexels.com/p']]]),
        ]);
        HttpFixtures::set('GET', 'https://images.pexels.com/p.jpg', ['response' => ['code' => 200], 'body' => $png]);

        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_preferred_image_provider', 'pexels');

        $engine = new ImageEngine([
            new KeiIaProvider(''),
            new PexelsProvider('pex-key'),
        ], new Logger(), $settings);

        $result = $engine->generate(new ImageRequest('a robot'));

        $this->assertSame('pexels', $result->getProvider());
    }

    public function testCascadeThrowsWhenEveryProviderFails(): void
    {
        $engine = new ImageEngine([new KeiIaProvider(''), new ReplicateProvider('')], new Logger());

        $this->expectException(ImageException::class);
        $this->expectExceptionMessageMatches('/All image providers failed/');
        $engine->generate(new ImageRequest('a robot'));
    }
}
