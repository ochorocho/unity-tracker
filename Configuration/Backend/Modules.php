<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

use Ochorocho\UnityTracker\Controller\ConnectionController;
use Ochorocho\UnityTracker\Controller\FilterController;
use Ochorocho\UnityTracker\Controller\ItemController;
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
            'newConnection' => [
                'target' => ConnectionController::class . '::newAction',
            ],
            'addConnection' => [
                'target' => ConnectionController::class . '::addAction',
                'methods' => ['POST'],
            ],
            'deleteConnection' => [
                'target' => ConnectionController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'item' => [
                'target' => ItemController::class . '::showAction',
            ],
            'itemAction' => [
                'target' => ItemController::class . '::performAction',
                'methods' => ['POST'],
            ],
            'itemPreview' => [
                'target' => ItemController::class . '::previewAction',
                'methods' => ['POST'],
            ],
            'itemUpload' => [
                'target' => ItemController::class . '::uploadAction',
                'methods' => ['POST'],
            ],
            'itemActivity' => [
                'target' => ItemController::class . '::activityAction',
            ],
            'itemSuggest' => [
                'target' => ItemController::class . '::suggestAction',
            ],
            'itemImage' => [
                'target' => ItemController::class . '::imageAction',
            ],
            'filters' => [
                'target' => FilterController::class . '::listAction',
            ],
            'newFilter' => [
                'target' => FilterController::class . '::newAction',
            ],
            'editFilter' => [
                'target' => FilterController::class . '::editAction',
            ],
            'saveFilter' => [
                'target' => FilterController::class . '::saveAction',
                'methods' => ['POST'],
            ],
            'deleteFilter' => [
                'target' => FilterController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
