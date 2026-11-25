<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Security;

use Ochorocho\UnityTracker\Security\TokenCipher;
use Ochorocho\UnityTracker\Security\TokenDecryptionException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TokenCipherTest extends UnitTestCase
{
    #[Test]
    public function encryptedTokenDecryptsToPlainText(): void
    {
        $cipher = TokenCipher::fromEncryptionKey('encryption-key');

        self::assertSame('glpat-secret', $cipher->decrypt($cipher->encrypt('glpat-secret')));
    }

    #[Test]
    public function encryptedTokenDoesNotContainPlainText(): void
    {
        $cipherText = TokenCipher::fromEncryptionKey('encryption-key')->encrypt('glpat-secret');

        self::assertStringNotContainsString('glpat-secret', $cipherText);
    }

    #[Test]
    public function sameTokenEncryptsDifferentlyEachTime(): void
    {
        $cipher = TokenCipher::fromEncryptionKey('encryption-key');

        self::assertNotSame($cipher->encrypt('glpat-secret'), $cipher->encrypt('glpat-secret'));
    }

    #[Test]
    public function otherEncryptionKeyCannotDecrypt(): void
    {
        $cipherText = TokenCipher::fromEncryptionKey('encryption-key')->encrypt('glpat-secret');

        $this->expectException(TokenDecryptionException::class);
        TokenCipher::fromEncryptionKey('other-key')->decrypt($cipherText);
    }

    #[Test]
    public function tamperedCipherTextCannotBeDecrypted(): void
    {
        $cipher = TokenCipher::fromEncryptionKey('encryption-key');
        $raw = base64_decode(substr($cipher->encrypt('glpat-secret'), 3), true);
        self::assertIsString($raw);
        $raw[-1] = $raw[-1] === 'a' ? 'b' : 'a';

        $this->expectException(TokenDecryptionException::class);
        $cipher->decrypt('v1:' . base64_encode($raw));
    }

    #[Test]
    public function plainTextValueIsRejected(): void
    {
        $this->expectException(TokenDecryptionException::class);
        TokenCipher::fromEncryptionKey('encryption-key')->decrypt('glpat-secret');
    }

    #[Test]
    public function emptyEncryptionKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        TokenCipher::fromEncryptionKey('');
    }
}
