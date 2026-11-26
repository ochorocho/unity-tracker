<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Controller;

use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Controller\ItemTablePresenter;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Item\Item;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ItemTablePresenterTest extends UnitTestCase
{
    #[Test]
    public function rowCarriesEverythingTheTableShows(): void
    {
        $item = new Item(
            Category::Todo,
            Provider::GitLab,
            'Work GitLab',
            'merge_request',
            'Fix the build',
            'https://gitlab.com/acme/app/-/merge_requests/12',
            'acme/app',
            '!12',
            'jane',
            new \DateTimeImmutable('2026-11-20T08:15:00+00:00'),
            ['bug'],
            'review_requested',
            true,
        );

        self::assertSame([[
            'connectionUid' => 0,
            'number' => 0,
            'todoId' => '',
            'category' => 'todo',
            'type' => 'merge_request',
            'title' => 'Fix the build',
            'url' => 'https://gitlab.com/acme/app/-/merge_requests/12',
            'project' => 'acme/app',
            'reference' => '!12',
            'author' => 'jane',
            'reason' => 'review_requested',
            'labels' => ['bug'],
            'draft' => true,
            'updatedAt' => '2026-11-20T08:15:00+00:00',
            'updatedAtFormatted' => '20.11.2026 08:15',
            'connection' => 'Work GitLab',
            'provider' => 'GitLab',
        ]], (new ItemTablePresenter())->rows([$item]));
    }

    #[Test]
    public function missingDateIsLeftEmpty(): void
    {
        $item = new Item(Category::AssignedIssue, Provider::GitHub, 'GitHub', 'issue', 'Crash', '', 'acme/app', '#3', 'joe', new \DateTimeImmutable('@0'));

        $row = (new ItemTablePresenter())->rows([$item])[0];

        self::assertSame(['', ''], [$row['updatedAt'], $row['updatedAtFormatted']]);
    }
}
