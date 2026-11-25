<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Security;

/**
 * Authenticated encryption (libsodium secretbox) for access tokens at rest.
 * The key is derived from TYPO3's encryptionKey; TYPO3 v13 has no core cipher service.
 */
final readonly class TokenCipher
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[\SensitiveParameter]
        private string $key,
    ) {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Token cipher key must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes long.', 1764072101);
        }
    }

    public static function fromEncryptionKey(#[\SensitiveParameter] string $encryptionKey): self
    {
        if ($encryptionKey === '') {
            throw new \RuntimeException('$GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'encryptionKey\'] is empty, tokens cannot be encrypted.', 1764072102);
        }
        return new self(hash_hkdf('sha256', $encryptionKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'unity_tracker:token'));
    }

    public static function fromGlobals(): self
    {
        return self::fromEncryptionKey((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''));
    }

    public function encrypt(#[\SensitiveParameter] string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plainText, $nonce, $this->key));
    }

    /**
     * @throws TokenDecryptionException
     */
    public function decrypt(string $cipherText): string
    {
        $raw = str_starts_with($cipherText, self::PREFIX)
            ? base64_decode(substr($cipherText, strlen(self::PREFIX)), true)
            : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new TokenDecryptionException('Token has an unknown format.', 1764072103);
        }
        $plainText = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plainText === false) {
            throw new TokenDecryptionException('Token cannot be decrypted, the encryptionKey has probably changed.', 1764072104);
        }
        return $plainText;
    }
}
