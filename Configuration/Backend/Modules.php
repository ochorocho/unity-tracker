<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

use Ochorocho\UnityTracker\Controller\ConnectionController;
use Ochorocho\UnityTracker\Controller\OverviewController;

return [
    'unity_tracker' => [
        'parent' => 'user',
        'position' => ['before' => '*'],
        'access' => 'user',
        'path' => '/module/user/unity-tracker',
        'iconIdentifier' => 'module-unity-tracker',
        'labels' => 'LLL:EXT:unity_tracker/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => OverviewController::class . '::overviewAction',
            ],
            'connections' => [
                'target' => ConnectionController::class . '::listAction',
            ],
            'addConnection' => [
                'target' => ConnectionController::class . '::addAction',
                'methods' => ['POST'],
            ],
            'deleteConnection' => [
                'target' => ConnectionController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
