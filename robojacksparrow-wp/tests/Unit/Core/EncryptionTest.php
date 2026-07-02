<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Core;

use RoboJackSparrow\Core\Encryption;
use RoboJackSparrow\Tests\TestCase;
use RuntimeException;

final class EncryptionTest extends TestCase
{
    public function testRoundTripReturnsOriginalPlaintext(): void
    {
        $encryption = new Encryption();
        $plaintext = 'sk-super-secret-api-key-12345';

        $encrypted = $encryption->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $encryption->decrypt($encrypted));
    }

    public function testEncryptingTheSamePlaintextTwiceProducesDifferentCiphertext(): void
    {
        $encryption = new Encryption();
        $plaintext = 'sk-same-key-both-times';

        $first = $encryption->encrypt($plaintext);
        $second = $encryption->encrypt($plaintext);

        $this->assertNotSame($first, $second, 'a fresh random IV must be used on every call');
        $this->assertSame($plaintext, $encryption->decrypt($first));
        $this->assertSame($plaintext, $encryption->decrypt($second));
    }

    public function testTamperedCiphertextFailsAuthentication(): void
    {
        $encryption = new Encryption();
        $encrypted = $encryption->encrypt('sk-secret');

        $raw = base64_decode($encrypted, true);
        $tampered = base64_encode(substr($raw, 0, -1) . chr((ord(substr($raw, -1)) + 1) % 256));

        $this->expectException(RuntimeException::class);
        $encryption->decrypt($tampered);
    }

    public function testDecryptingGarbageInputFails(): void
    {
        $encryption = new Encryption();

        $this->expectException(RuntimeException::class);
        $encryption->decrypt('not-even-valid-base64-ciphertext-!!!');
    }
}
