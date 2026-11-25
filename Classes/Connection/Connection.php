<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Connection;

/**
 * A backend user's access to one GitLab or GitHub host.
 */
final readonly class Connection
{
    public function __construct(
        public int $uid,
        public int $backendUser,
        public Provider $provider,
        public string $host,
        public string $label,
        #[\SensitiveParameter]
        public string $token,
        public string $account = '',
    ) {}

    public function apiBaseUrl(): string
    {
        return $this->provider->apiBaseUrl($this->host);
    }

    public function name(): string
    {
        return $this->label !== '' ? $this->label : $this->provider->label() . ' (' . $this->host . ')';
    }

    /**
     * Empty when the stored token could not be decrypted (e.g. after the encryptionKey changed).
     */
    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function withAccount(string $account): self
    {
        return new self($this->uid, $this->backendUser, $this->provider, $this->host, $this->label, $this->token, $account);
    }

    /**
     * Keep the token out of var_dump() and debug output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'uid' => $this->uid,
            'backendUser' => $this->backendUser,
            'provider' => $this->provider,
            'host' => $this->host,
            'label' => $this->label,
            'account' => $this->account,
            'token' => $this->hasToken() ? '***' : '',
        ];
    }
}
