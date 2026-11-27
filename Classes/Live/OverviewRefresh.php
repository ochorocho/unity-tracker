<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Overviews are refreshed in the messenger worker and pushed as live updates: right after an action
 * (request()) and every `refreshInterval` seconds for the logged-in users, by a RefreshActiveOverviews
 * message that dispatches itself again with a delay.
 *
 * The cache holds the current chain: its token and when its next message is due. A chain that missed its slot
 * (worker stopped, queue or cache cleared) is restarted by the next overview visit (keepRunning()). Not the
 * registry: it keeps entries in memory, a long-running worker would never see a new chain.
 */
final readonly class OverviewRefresh
{
    private const CACHE_IDENTIFIER = 'refresh_chain';
    private const SYNC_TRANSPORT = 'default';

    public function __construct(
        private LiveUpdates $liveUpdates,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'cache.unitytracker')]
        private FrontendInterface $cache,
        private ExtensionSettings $settings,
        private ClockInterface $clock,
    ) {}

    /**
     * Refreshes need the hub and a worker: with the synchronous transport they would run inside the request.
     */
    public function isAvailable(): bool
    {
        return $this->liveUpdates->isEnabled() && self::isAsync(RefreshOverview::class) && self::isAsync(RefreshActiveOverviews::class);
    }

    public function request(int $backendUser): void
    {
        if ($backendUser > 0 && $this->isAvailable()) {
            $this->messageBus->dispatch(new RefreshOverview($backendUser));
        }
    }

    /**
     * Starts the repeating refresh unless a chain is due within its interval.
     */
    public function keepRunning(): void
    {
        $interval = $this->settings->refreshInterval;
        if ($interval === 0 || !$this->isAvailable()) {
            return;
        }
        $state = $this->cache->get(self::CACHE_IDENTIFIER);
        $now = $this->clock->now()->getTimestamp();
        // One interval of grace: the worker may be busy.
        if (is_array($state) && (int)($state['due'] ?? 0) + $interval >= $now) {
            return;
        }
        $token = bin2hex(random_bytes(8));
        $this->cache->set(self::CACHE_IDENTIFIER, ['token' => $token, 'due' => $now], [], 0);
        $this->messageBus->dispatch(new RefreshActiveOverviews($token));
    }

    public function isCurrent(string $token): bool
    {
        $state = $this->cache->get(self::CACHE_IDENTIFIER);
        return is_array($state) && ($state['token'] ?? null) === $token;
    }

    /**
     * Dispatches the chain's next message, or ends the chain when refreshing was switched off.
     */
    public function scheduleNext(string $token): void
    {
        $interval = $this->settings->refreshInterval;
        if ($interval === 0 || !$this->isAvailable()) {
            $this->cache->remove(self::CACHE_IDENTIFIER);
            return;
        }
        $due = $this->clock->now()->getTimestamp() + $interval;
        $this->cache->set(self::CACHE_IDENTIFIER, ['token' => $token, 'due' => $due], [], 0);
        $this->messageBus->dispatch(new RefreshActiveOverviews($token), [new DelayStamp($interval * 1000)]);
    }

    /**
     * Whether the message is routed to a transport other than the synchronous "default". Checks the class and
     * the global "*" route, the two that ext_localconf.php and TYPO3's default configuration set.
     *
     * @param class-string $message
     */
    private static function isAsync(string $message): bool
    {
        $routing = $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] ?? [];
        $routing = is_array($routing) ? $routing : [];
        $transports = array_filter((array)($routing[$message] ?? $routing['*'] ?? self::SYNC_TRANSPORT), is_string(...));
        return $transports !== [] && !in_array(self::SYNC_TRANSPORT, $transports, true);
    }
}
