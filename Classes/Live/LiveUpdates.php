<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pushes updates to open backend pages. MercureLiveUpdates with EXT:mercure (Configuration/Services.php),
 * otherwise DisabledLiveUpdates: pages then behave as without live updates.
 */
interface LiveUpdates
{
    public function isEnabled(): bool;

    /**
     * Allows the current backend user to receive these topics (subscriber cookie on the response).
     *
     * @return string URL for an EventSource, "" when live updates are off
     */
    public function subscribe(ServerRequestInterface $request, string ...$topics): string;

    /**
     * Sends a private update to the subscribers of the topic, through the message bus.
     *
     * @param array<string, mixed> $data JSON encoded, "type" tells the browser what it is
     */
    public function publish(string $topic, array $data): void;
}
