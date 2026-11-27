<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshActiveOverviewsHandler
{
    public function __construct(
        private OverviewRefresh $overviewRefresh,
        private ActiveBackendUsers $activeBackendUsers,
    ) {}

    public function __invoke(RefreshActiveOverviews $message): void
    {
        if (!$this->overviewRefresh->isCurrent($message->token)) {
            return;
        }
        foreach ($this->activeBackendUsers->ids() as $backendUser) {
            $this->overviewRefresh->request($backendUser);
        }
        $this->overviewRefresh->scheduleNext($message->token);
    }
}
