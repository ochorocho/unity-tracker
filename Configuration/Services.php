<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\UnityTracker\Live\DisabledLiveUpdates;
use Ochorocho\UnityTracker\Live\LiveUpdates;
use Ochorocho\UnityTracker\Live\MercureLiveUpdates;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Live updates through EXT:mercure (ochorocho/typo3-mercure), an optional dependency (see composer.json "suggest").
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();
    if (class_exists(SubscriberGrants::class)) {
        $services->set(MercureLiveUpdates::class)->autowire();
        $services->alias(LiveUpdates::class, MercureLiveUpdates::class);
    } else {
        $services->alias(LiveUpdates::class, DisabledLiveUpdates::class);
    }
};
