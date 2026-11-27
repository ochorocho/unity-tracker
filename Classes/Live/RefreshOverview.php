<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

/**
 * Message: fetch the overview of one backend user again and push its rows if they changed.
 */
final readonly class RefreshOverview
{
    public function __construct(public int $backendUser) {}
}
