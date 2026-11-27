<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\ItemReference;
use Ochorocho\UnityTracker\Live\Topics;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TopicsTest extends UnitTestCase
{
    #[Test]
    public function userTopicNamesTheBackendUser(): void
    {
        self::assertSame('urn:unity-tracker:user:7', Topics::user(7));
    }

    #[Test]
    public function itemTopicIsTheSameForEveryConnectionOfTheHost(): void
    {
        $reference = ItemReference::fromArray(['connection' => 3, 'type' => 'merge_request', 'project' => 'Acme/App', 'number' => 12, 'todo' => '99']);
        $mine = new Connection(3, 1, Provider::GitLab, 'GitLab.com', 'Mine', 'token');
        $theirs = new Connection(8, 2, Provider::GitLab, 'gitlab.com', 'Theirs', 'other');

        self::assertSame('urn:unity-tracker:item:gitlab.com/acme/app/merge_request/12', Topics::item($mine, $reference));
        self::assertSame(Topics::item($mine, $reference), Topics::item($theirs, $reference->withoutTodo()));
    }
}
