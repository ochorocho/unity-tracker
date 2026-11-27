<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

/**
 * Message that repeats itself (see OverviewRefresh): refreshes the overviews of the logged-in backend users.
 * Only the message of the current chain (token) runs, duplicates die out.
 */
final readonly class RefreshActiveOverviews
{
    public function __construct(public string $token) {}
}
