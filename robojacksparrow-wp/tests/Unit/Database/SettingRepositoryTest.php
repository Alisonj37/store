<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Database;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Tests\TestCase;

final class SettingRepositoryTest extends TestCase
{
    public function testSensitiveValueRoundTripsAndIsEncryptedAtRest(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_tavily_api_key', 'tvly-real-secret-key', 'string', true);

        $this->assertNotSame('tvly-real-secret-key', $this->wpdb->settings['rjs_tavily_api_key']['setting_value']);
        $this->assertSame(1, $this->wpdb->settings['rjs_tavily_api_key']['is_sensitive']);
        $this->assertSame('tvly-real-secret-key', $settings->get('rjs_tavily_api_key'));
    }

    public function testGetMaskedNeverExposesTheFullSecret(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());
        $settings->set('rjs_openai_api_key', 'sk-openai-1234567890abcdef', 'string', true);

        $masked = $settings->getMasked('rjs_openai_api_key');

        $this->assertNotNull($masked);
        $this->assertStringNotContainsString('1234567890', $masked);
        $this->assertStringStartsWith('sk-o', $masked);
    }

    public function testUnsetKeyReturnsTheProvidedDefault(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());

        $this->assertSame('fallback', $settings->get('rjs_never_set', 'fallback'));
    }

    public function testCorruptedCiphertextDoesNotFatalButReturnsTheDefault(): void
    {
        // Simulates a rotated AUTH_KEY/SECURE_AUTH_KEY, or a DB restored
        // from a different site: the stored ciphertext can no longer be
        // decrypted with the current key.
        $this->wpdb->settings['rjs_openai_api_key'] = [
            'id'            => 1,
            'setting_key'   => 'rjs_openai_api_key',
            'setting_value' => 'not-valid-ciphertext-for-this-key',
            'setting_type'  => 'encrypted',
            'is_sensitive'  => 1,
        ];

        $settings = new SettingRepository(new Encryption(), new Logger());

        $this->assertSame('fallback', $settings->get('rjs_openai_api_key', 'fallback'));
        $this->assertNull($settings->getMasked('rjs_openai_api_key'), 'getMasked() must also degrade instead of fataling');
    }

    public function testIsSensitiveDefaultsToTrueForUnknownApiKeySuffixedKeys(): void
    {
        $settings = new SettingRepository(new Encryption(), new Logger());

        $this->assertTrue($settings->isSensitive('rjs_some_new_provider_api_key'));
        $this->assertFalse($settings->isSensitive('rjs_content_language'));
    }
}
