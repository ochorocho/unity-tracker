<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

$config = \TYPO3\CodingStandards\CsFixerConfig::create()
    ->setHeader('This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.', true);
$config->getFinder()
    ->in([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
    ])
    // Stock bootstrap copied from typo3/testing-framework.
    ->notName('UnitTestsBootstrap.php')
    ->append([
        __DIR__ . '/ext_localconf.php',
    ]);
return $config;
