<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Ochorocho\UnityTracker\Controller\ItemTablePresenter;
use Ochorocho\UnityTracker\Service\OverviewService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fetches the overview again (which also renews the cache) and pushes the rows when they differ from the
 * cached ones. A result with errors is not pushed: one host being down must not empty the open overview.
 */
#[AsMessageHandler]
final readonly class RefreshOverviewHandler
{
    public function __construct(
        private ConnectionRepository $connectionRepository,
        private OverviewService $overviewService,
        private ItemTablePresenter $itemTablePresenter,
        private LiveUpdates $liveUpdates,
    ) {}

    public function __invoke(RefreshOverview $message): void
    {
        $backendUser = $message->backendUser;
        if ($this->connectionRepository->findByBackendUser($backendUser) === []) {
            return;
        }
        $before = $this->overviewService->cached($backendUser);
        $overview = $this->overviewService->forBackendUser($backendUser, true);
        if ($overview->errors !== []) {
            return;
        }
        $rows = $this->itemTablePresenter->rows($overview->items);
        if ($before !== null && $this->itemTablePresenter->rows($before->items) === $rows) {
            return;
        }
        $this->liveUpdates->publish(Topics::user($backendUser), [
            'type' => 'overview',
            'items' => $rows,
            'fetchedAt' => $overview->fetchedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
