<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// Per-user overviews (items only, never tokens); lifetime comes from the extension configuration.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['unitytracker'] ??= [
    'frontend' => VariableFrontend::class,
];

// Overview refreshes run in the messenger worker, on the transport EXT:mercure publishes its updates with
// (default "doctrine"). Without EXT:mercure, or with its transport empty (synchronous), they are not used.
if (class_exists(\Ochorocho\Mercure\Http\SubscriberGrants::class)) {
    $unityTrackerTransport = trim((string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mercure']['transport'] ?? 'doctrine'));
    if ($unityTrackerTransport !== '') {
        foreach ([\Ochorocho\UnityTracker\Live\RefreshOverview::class, \Ochorocho\UnityTracker\Live\RefreshActiveOverviews::class] as $unityTrackerMessage) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][$unityTrackerMessage] ??= $unityTrackerTransport;
        }
    }
    unset($unityTrackerTransport, $unityTrackerMessage);
}
