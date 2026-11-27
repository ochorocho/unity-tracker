<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Detail\ItemReference;

/**
 * Mercure topics. Updates are private: a browser only gets the topics it was granted (see LiveUpdates::subscribe()).
 */
final class Topics
{
    private const PREFIX = 'urn:unity-tracker:';

    /**
     * Overview rows of one backend user.
     */
    public static function user(int $backendUser): string
    {
        return self::PREFIX . 'user:' . $backendUser;
    }

    /**
     * One issue, merge request or pull request, the same for every backend user and connection of that host.
     */
    public static function item(Connection $connection, ItemReference $reference): string
    {
        // GitLab and GitHub paths are case insensitive.
        return self::PREFIX . 'item:' . strtolower($connection->host . '/' . $reference->project) . '/' . $reference->type . '/' . $reference->number;
    }
}
