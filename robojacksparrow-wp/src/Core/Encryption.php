<?php

declare(strict_types=1);

namespace RoboJackSparrow\Core;

use RuntimeException;

/**
 * Criptografia simetrica AES-256-GCM para dados sensiveis em repouso
 * (API keys). A chave e derivada dos salts nativos do WordPress
 * (AUTH_KEY/SECURE_AUTH_KEY, definidos em wp-config.php) para nao exigir
 * gerenciamento de segredo adicional; se ausentes (ex.: ambiente de teste),
 * um segredo de fallback e gerado e persistido via option.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unsupported cipher: ' . self::CIPHER);
        }

        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new RuntimeException('Invalid encrypted payload (not valid base64)');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unsupported cipher: ' . self::CIPHER);
        }

        if (strlen($raw) < $ivLength + self::TAG_LENGTH) {
            throw new RuntimeException('Invalid encrypted payload (too short)');
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (tampered data or wrong key)');
        }

        return $plaintext;
    }

    /**
     * Deriva uma chave de 32 bytes (AES-256) a partir dos salts do WP.
     */
    private function deriveKey(): string
    {
        $secret = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');

        if ($secret === '') {
            $secret = $this->fallbackSecret();
        }

        return hash('sha256', $secret . '|robojacksparrow-wp', true);
    }

    private function fallbackSecret(): string
    {
        $stored = get_option('rjs_encryption_fallback_secret');
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $generated = base64_encode(random_bytes(32));
        update_option('rjs_encryption_fallback_secret', $generated);

        return $generated;
    }
}
