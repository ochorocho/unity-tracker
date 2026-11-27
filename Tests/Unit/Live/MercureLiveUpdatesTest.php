<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\UnityTracker\Live\MercureLiveUpdates;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mercure\Update;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MercureLiveUpdatesTest extends UnitTestCase
{
    #[Test]
    public function subscribeGrantsTheTopicsAndAnswersTheHubUrl(): void
    {
        $grants = new SubscriberGrants();
        $request = (new ServerRequest('https://example.com/typo3/'))->withAttribute(SubscriberGrants::ATTRIBUTE, $grants);

        $url = (new MercureLiveUpdates(new FakeHub(), new RecordingBus()))->subscribe($request, 'urn:a', 'urn:b');

        self::assertSame('https://example.com/.well-known/mercure?match=urn%3Aa&match=urn%3Ab', $url);
        self::assertSame(['urn:a', 'urn:b'], $grants->getTopics());
    }

    #[Test]
    public function subscribeAnswersNothingWithoutTheMiddleware(): void
    {
        $url = (new MercureLiveUpdates(new FakeHub(), new RecordingBus()))->subscribe(new ServerRequest('https://example.com/typo3/'), 'urn:a');

        self::assertSame('', $url);
    }

    #[Test]
    public function subscribeAnswersNothingOnAnotherOrigin(): void
    {
        $live = new MercureLiveUpdates(new FakeHub(), new RecordingBus());

        foreach (['http://example.com/typo3/', 'https://web/typo3/', 'https://example.com:8443/typo3/'] as $url) {
            $grants = new SubscriberGrants();
            $request = (new ServerRequest($url))->withAttribute(SubscriberGrants::ATTRIBUTE, $grants);

            self::assertSame('', $live->subscribe($request, 'urn:a'), $url);
            self::assertSame([], $grants->getTopics(), $url);
        }
    }

    #[Test]
    public function subscribeAcceptsTheDefaultPortSpelledOut(): void
    {
        $request = (new ServerRequest('https://example.com:443/typo3/'))->withAttribute(SubscriberGrants::ATTRIBUTE, new SubscriberGrants());

        self::assertNotSame('', (new MercureLiveUpdates(new FakeHub(), new RecordingBus()))->subscribe($request, 'urn:a'));
    }

    #[Test]
    public function unconfiguredHubIsOff(): void
    {
        $grants = new SubscriberGrants();
        $bus = new RecordingBus();
        $live = new MercureLiveUpdates(new FakeHub(configured: false), $bus);

        $url = $live->subscribe((new ServerRequest('https://example.com/typo3/'))->withAttribute(SubscriberGrants::ATTRIBUTE, $grants), 'urn:a');
        $live->publish('urn:a', ['type' => 'test']);

        self::assertFalse($live->isEnabled());
        self::assertSame('', $url);
        self::assertSame([], $grants->getTopics());
        self::assertSame([], $bus->envelopes);
    }

    #[Test]
    public function publishSendsAPrivateUpdateThroughTheBus(): void
    {
        $bus = new RecordingBus();

        (new MercureLiveUpdates(new FakeHub(), $bus))->publish('urn:a', ['type' => 'item-changed', 'action' => 'comment']);

        $update = $bus->messages()[0] ?? null;
        self::assertInstanceOf(Update::class, $update);
        self::assertSame(['urn:a'], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame('{"type":"item-changed","action":"comment"}', $update->getData());
    }
}
