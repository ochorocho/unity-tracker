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
