<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Connection;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;

/**
 * Hosts are requested server-side with the user's token. Only https and hosts the admin
 * allowed are accepted, so editors cannot point TYPO3 at arbitrary (internal) addresses.
 */
final readonly class HostPolicy
{
    public function __construct(private ExtensionSettings $settings) {}

    /**
     * @return string normalised "host" or "host:port", lower case
     * @throws InvalidHostException
     */
    public function resolve(string $input, Provider $provider): string
    {
        $input = trim($input);
        if ($input === '') {
            return $provider->defaultHost();
        }

        $url = str_contains($input, '://') ? $input : 'https://' . $input;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new InvalidHostException(InvalidHostException::MALFORMED, $input, 1764072001);
        }
        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidHostException(InvalidHostException::NOT_HTTPS, $input, 1764072002);
        }

        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (!$this->isAllowed($host)) {
            throw new InvalidHostException(InvalidHostException::NOT_ALLOWED, $host, 1764072003);
        }
        return $host;
    }

    public function isAllowed(string $host): bool
    {
        return in_array($host, $this->allowedHosts(), true);
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        return array_values(array_unique([
            Provider::GitLab->defaultHost(),
            Provider::GitHub->defaultHost(),
            ...$this->settings->allowedHosts,
        ]));
    }
}
