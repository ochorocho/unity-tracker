<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Provider;

use Ochorocho\UnityTracker\Connection\Provider;

final readonly class ProviderRegistry
{
    public function __construct(
        private GitLabProvider $gitLab,
        private GitHubProvider $gitHub,
    ) {}

    public function get(Provider $provider): ProviderInterface
    {
        return match ($provider) {
            Provider::GitLab => $this->gitLab,
            Provider::GitHub => $this->gitHub,
        };
    }
}
