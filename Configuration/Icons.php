<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-unity-tracker' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:unity_tracker/Resources/Public/Icons/Extension.svg',
    ],
];
