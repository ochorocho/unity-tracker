<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

return [
    'dependencies' => ['core', 'backend'],
    'imports' => [
        '@ochorocho/unity-tracker/' => 'EXT:unity_tracker/Resources/Public/JavaScript/',
    ],
];
