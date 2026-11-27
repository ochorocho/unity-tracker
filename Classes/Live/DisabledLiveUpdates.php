<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Without EXT:mercure.
 */
final readonly class DisabledLiveUpdates implements LiveUpdates
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function subscribe(ServerRequestInterface $request, string ...$topics): string
    {
        return '';
    }

    public function publish(string $topic, array $data): void {}
}
