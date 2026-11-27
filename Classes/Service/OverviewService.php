<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Service;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;
use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Per-user overview, cached for a few minutes. Results with errors are not cached,
 * so a host that was down is asked again on the next visit.
 */
final readonly class OverviewService
{
    public function __construct(
        private ConnectionRepository $connectionRepository,
        private ItemFetcher $itemFetcher,
        #[Autowire(service: 'cache.unitytracker')]
        private FrontendInterface $cache,
        private ExtensionSettings $settings,
    ) {}

    public function forBackendUser(int $backendUser, bool $refresh = false): Overview
    {
        $identifier = $this->identifier($backendUser);
        if (!$refresh && $this->settings->cacheLifetime > 0) {
            $cached = $this->cache->get($identifier);
            if ($cached instanceof Overview) {
                return $cached;
            }
        }

        $overview = $this->itemFetcher->fetch($this->connectionRepository->findByBackendUser($backendUser));
        if ($this->settings->cacheLifetime > 0 && $overview->errors === []) {
            $this->cache->set($identifier, $overview, [], $this->settings->cacheLifetime);
        } else {
            $this->cache->remove($identifier);
        }
        return $overview;
    }

    /**
     * The cached overview without fetching, null if there is none (or caching is off).
     */
    public function cached(int $backendUser): ?Overview
    {
        $cached = $this->settings->cacheLifetime > 0 ? $this->cache->get($this->identifier($backendUser)) : null;
        return $cached instanceof Overview ? $cached : null;
    }

    public function invalidate(int $backendUser): void
    {
        $this->cache->remove($this->identifier($backendUser));
    }

    private function identifier(int $backendUser): string
    {
        // Bump the version when Item changes shape: old cache entries are then ignored.
        return 'overview_v2_' . $backendUser;
    }
}
