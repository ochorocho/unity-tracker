<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Connection;

enum Provider: string
{
    case GitLab = 'gitlab';
    case GitHub = 'github';

    public function label(): string
    {
        return match ($this) {
            self::GitLab => 'GitLab',
            self::GitHub => 'GitHub',
        };
    }

    public function defaultHost(): string
    {
        return match ($this) {
            self::GitLab => 'gitlab.com',
            self::GitHub => 'github.com',
        };
    }

    public function apiBaseUrl(string $host): string
    {
        return match ($this) {
            self::GitLab => 'https://' . $host . '/api/v4',
            // github.com has a separate API host, GitHub Enterprise Server serves the API under /api/v3.
            self::GitHub => $host === 'github.com' ? 'https://api.github.com' : 'https://' . $host . '/api/v3',
        };
    }

    public function graphqlUrl(string $host): string
    {
        return match ($this) {
            self::GitLab => 'https://' . $host . '/api/graphql',
            self::GitHub => $host === 'github.com' ? 'https://api.github.com/graphql' : 'https://' . $host . '/api/graphql',
        };
    }
}
