<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;
use Ochorocho\UnityTracker\Live\ActiveBackendUsers;
use Ochorocho\UnityTracker\Live\MercureLiveUpdates;
use Ochorocho\UnityTracker\Live\OverviewRefresh;
use Ochorocho\UnityTracker\Live\RefreshActiveOverviews;
use Ochorocho\UnityTracker\Live\RefreshActiveOverviewsHandler;
use Ochorocho\UnityTracker\Live\RefreshOverview;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RefreshActiveOverviewsHandlerTest extends UnitTestCase
{
    private RecordingBus $bus;
    private OverviewRefresh $overviewRefresh;
    private RefreshActiveOverviewsHandler $subject;

    // The cache frontend creates a logger through LogManager.
    protected bool $resetSingletonInstances = true;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = ['*' => 'doctrine'];
        $GLOBALS['TYPO3_CONF_VARS']['BE']['sessionTimeout'] = 3600;
        $clock = new MockClock('2026-11-27 10:00:00');
        $now = $clock->now()->getTimestamp();

        $sessions = self::createStub(SessionBackendInterface::class);
        $sessions->method('getAll')->willReturn([
            ['ses_userid' => 2, 'ses_tstamp' => $now - 60],
            ['ses_userid' => 2, 'ses_tstamp' => $now - 10],
            ['ses_userid' => 5, 'ses_tstamp' => $now - 7200],
            ['ses_userid' => 0, 'ses_tstamp' => $now],
            ['ses_userid' => 9, 'ses_tstamp' => $now - 3599],
        ]);
        $sessionManager = self::createStub(SessionManager::class);
        $sessionManager->method('getSessionBackend')->willReturn($sessions);

        $this->bus = new RecordingBus();
        $this->overviewRefresh = new OverviewRefresh(
            new MercureLiveUpdates(new FakeHub(), new RecordingBus()),
            $this->bus,
            new VariableFrontend('unitytracker', new TransientMemoryBackend()),
            new ExtensionSettings(refreshInterval: 60),
            $clock,
        );
        $this->subject = new RefreshActiveOverviewsHandler($this->overviewRefresh, new ActiveBackendUsers($sessionManager, $clock));
    }

    #[Test]
    public function refreshesEveryLoggedInUserOnceAndRepeats(): void
    {
        $this->overviewRefresh->keepRunning();
        $start = $this->bus->messages()[0];
        self::assertInstanceOf(RefreshActiveOverviews::class, $start);

        ($this->subject)($start);

        self::assertEquals(
            [$start, new RefreshOverview(2), new RefreshOverview(9), new RefreshActiveOverviews($start->token)],
            $this->bus->messages()
        );
    }

    #[Test]
    public function messageOfAnOldChainIsDropped(): void
    {
        $this->overviewRefresh->keepRunning();

        ($this->subject)(new RefreshActiveOverviews('stale'));

        self::assertCount(1, $this->bus->envelopes);
    }
}
