<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use Ochorocho\UnityTracker\Detail\TaskList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TaskListTest extends UnitTestCase
{
    #[Test]
    public function nthItemIsTickedAndUnticked(): void
    {
        $markdown = "Intro\n- [ ] one\n- [x] two\n- [ ] three";

        self::assertSame("Intro\n- [ ] one\n- [x] two\n- [x] three", TaskList::toggle($markdown, 2, true));
        self::assertSame("Intro\n- [ ] one\n- [ ] two\n- [ ] three", TaskList::toggle($markdown, 1, false));
    }

    #[Test]
    public function tickingTwiceKeepsTheItemTicked(): void
    {
        self::assertSame('- [x] done', TaskList::toggle('- [X] done', 0, true));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function markdownWithTasks(): array
    {
        return [
            'other markers' => ["* [ ] a\n+ [ ] b\n1. [ ] c\n2) [ ] d", 3, "* [ ] a\n+ [ ] b\n1. [ ] c\n2) [x] d"],
            'nested' => ["- [ ] a\n  - [ ] b\n    1. [ ] c", 2, "- [ ] a\n  - [ ] b\n    1. [x] c"],
            'block quote' => ["> - [ ] quoted\n- [ ] plain", 0, "> - [x] quoted\n- [ ] plain"],
            'code fences are skipped' => ["```md\n- [ ] not a task\n```\n~~~\n- [ ] neither\n~~~\n- [ ] task", 0, "```md\n- [ ] not a task\n```\n~~~\n- [ ] neither\n~~~\n- [x] task"],
            'a longer fence holds a shorter one' => ["````\n```\n- [ ] code\n````\n- [ ] task", 0, "````\n```\n- [ ] code\n````\n- [x] task"],
            'CRLF line breaks stay' => ["- [ ] a\r\n- [ ] b\r\n", 1, "- [ ] a\r\n- [x] b\r\n"],
            'empty item' => ["- [ ]\n- [ ] b", 0, "- [x]\n- [ ] b"],
        ];
    }

    #[Test]
    #[DataProvider('markdownWithTasks')]
    public function onlyTaskItemsOutsideCodeCount(string $markdown, int $index, string $expected): void
    {
        self::assertSame($expected, TaskList::toggle($markdown, $index, true));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function noSuchTask(): array
    {
        return [
            'index too high' => ['- [ ] a', 1],
            'negative index' => ['- [ ] a', -1],
            'no list marker' => ['[ ] a', 0],
            'link, not a task' => ['- [x](https://example.com)', 0],
            'no space after the marker' => ['-[ ] a', 0],
        ];
    }

    #[Test]
    #[DataProvider('noSuchTask')]
    public function missingTaskIsNull(string $markdown, int $index): void
    {
        self::assertNull(TaskList::toggle($markdown, $index, true));
    }

    #[Test]
    public function inapplicableItemsCountOnGitLabButCannotBeTicked(): void
    {
        $markdown = "- [~] dropped\n- [ ] open";

        self::assertSame("- [~] dropped\n- [x] open", TaskList::toggle($markdown, 1, true, inapplicable: true));
        self::assertNull(TaskList::toggle($markdown, 0, true, inapplicable: true));
        self::assertSame("- [~] dropped\n- [x] open", TaskList::toggle($markdown, 0, true), 'GitHub: "[~]" is text');
    }
}
