<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;
use Ochorocho\UnityTracker\Live\DisabledLiveUpdates;
use Ochorocho\UnityTracker\Live\LiveUpdates;
use Ochorocho\UnityTracker\Live\MercureLiveUpdates;
use Ochorocho\UnityTracker\Live\OverviewRefresh;
use Ochorocho\UnityTracker\Live\RefreshActiveOverviews;
use Ochorocho\UnityTracker\Live\RefreshOverview;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class OverviewRefreshTest extends UnitTestCase
{
    private RecordingBus $bus;
    private VariableFrontend $cache;
    private MockClock $clock;

    // The cache frontend creates a logger through LogManager.
    protected bool $resetSingletonInstances = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bus = new RecordingBus();
        $this->cache = new VariableFrontend('unitytracker', new TransientMemoryBackend());
        $this->clock = new MockClock('2026-11-27 10:00:00');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = [
            '*' => 'default',
            RefreshOverview::class => 'doctrine',
            RefreshActiveOverviews::class => 'doctrine',
        ];
    }

    private function subject(int $interval = 120, ?LiveUpdates $liveUpdates = null): OverviewRefresh
    {
        return new OverviewRefresh(
            $liveUpdates ?? new MercureLiveUpdates(new FakeHub(), new RecordingBus()),
            $this->bus,
            $this->cache,
            new ExtensionSettings(refreshInterval: $interval),
            $this->clock,
        );
    }

    #[Test]
    public function requestQueuesARefreshOfTheUser(): void
    {
        $this->subject()->request(2);

        self::assertEquals([new RefreshOverview(2)], $this->bus->messages());
    }

    #[Test]
    public function nothingIsQueuedWithoutLiveUpdates(): void
    {
        $subject = $this->subject(liveUpdates: new DisabledLiveUpdates());
        $subject->request(2);
        $subject->keepRunning();

        self::assertFalse($subject->isAvailable());
        self::assertSame([], $this->bus->envelopes);
    }

    #[Test]
    public function nothingIsQueuedOnTheSynchronousTransport(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = ['*' => 'default'];
        $subject = $this->subject();
        $subject->request(2);

        self::assertFalse($subject->isAvailable());
        self::assertSame([], $this->bus->envelopes);
    }

    #[Test]
    public function keepRunningStartsOneChain(): void
    {
        $subject = $this->subject();
        $subject->keepRunning();
        $subject->keepRunning();

        $messages = $this->bus->messages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(RefreshActiveOverviews::class, $messages[0]);
        self::assertTrue($subject->isCurrent($messages[0]->token));
    }

    #[Test]
    public function keepRunningRestartsAChainThatMissedItsSlot(): void
    {
        $subject = $this->subject();
        $subject->keepRunning();
        $first = $this->bus->messages()[0];
        self::assertInstanceOf(RefreshActiveOverviews::class, $first);

        $this->clock->modify('+241 seconds');
        $subject->keepRunning();

        $second = $this->bus->messages()[1] ?? null;
        self::assertInstanceOf(RefreshActiveOverviews::class, $second);
        self::assertFalse($subject->isCurrent($first->token));
        self::assertTrue($subject->isCurrent($second->token));
    }

    #[Test]
    public function scheduleNextDispatchesTheChainAgainAfterTheInterval(): void
    {
        $subject = $this->subject(interval: 90);
        $subject->keepRunning();
        $first = $this->bus->messages()[0];
        self::assertInstanceOf(RefreshActiveOverviews::class, $first);

        $subject->scheduleNext($first->token);
        $this->clock->modify('+100 seconds');
        $subject->keepRunning();

        $envelope = $this->bus->envelopes[1];
        self::assertEquals(new RefreshActiveOverviews($first->token), $envelope->getMessage());
        self::assertSame(90000, $envelope->last(DelayStamp::class)?->getDelay());
        // Due within the grace period: no second chain.
        self::assertCount(2, $this->bus->envelopes);
    }

    #[Test]
    public function scheduleNextEndsTheChainWhenSwitchedOff(): void
    {
        $this->subject()->keepRunning();
        $first = $this->bus->messages()[0];
        self::assertInstanceOf(RefreshActiveOverviews::class, $first);

        $subject = $this->subject(interval: 0);
        $subject->scheduleNext($first->token);

        self::assertCount(1, $this->bus->envelopes);
        self::assertFalse($subject->isCurrent($first->token));
    }
}
