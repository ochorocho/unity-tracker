<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Provider;

use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Provider\GitLabProvider;
use Ochorocho\UnityTracker\Provider\Query;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GitLabProviderTest extends UnitTestCase
{
    private GitLabProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new GitLabProvider();
    }

    #[Test]
    public function requestsAuthenticateWithPrivateToken(): void
    {
        $request = $this->subject->accountRequest(Fixture::connection(Provider::GitLab, 'git.example.com'));

        self::assertSame('https://git.example.com/api/v4/user', (string)$request->getUri());
        self::assertSame('secret-token', $request->getHeaderLine('PRIVATE-TOKEN'));
    }

    #[Test]
    public function accountIsTheUsername(): void
    {
        self::assertSame('jane', $this->subject->parseAccount(['id' => 2, 'username' => 'jane']));
    }

    #[Test]
    public function queriesCoverEveryCategory(): void
    {
        $queries = $this->subject->queries(Fixture::connection(Provider::GitLab));
        $uris = [];
        foreach ($queries as $query) {
            $uris[$query->category->value] = (string)$query->request->getUri();
        }

        self::assertSame([
            'todo' => 'https://gitlab.com/api/v4/todos?state=pending&per_page=50',
            'reviewRequested' => 'https://gitlab.com/api/v4/merge_requests?scope=all&reviewer_username=me&state=opened&order_by=updated_at&per_page=50',
            'assignedChange' => 'https://gitlab.com/api/v4/merge_requests?scope=assigned_to_me&state=opened&order_by=updated_at&per_page=50',
            'authoredChange' => 'https://gitlab.com/api/v4/merge_requests?scope=created_by_me&state=opened&order_by=updated_at&per_page=50',
            'assignedIssue' => 'https://gitlab.com/api/v4/issues?state=opened&scope=assigned_to_me&order_by=updated_at&per_page=50',
        ], $uris);
    }

    #[Test]
    public function todoOnMergeRequestIsParsed(): void
    {
        $item = $this->parse(Category::Todo, 'GitLab/todos.json')[0];

        self::assertSame(Category::Todo, $item->category);
        self::assertSame('merge_request', $item->type);
        self::assertSame('Add search', $item->title);
        self::assertSame('https://gitlab.com/acme/website/-/merge_requests/7', $item->url);
        self::assertSame('acme/website', $item->project);
        self::assertSame('!7', $item->reference);
        self::assertSame('jane', $item->author);
        self::assertSame('review_requested', $item->reason);
        self::assertSame(['frontend', 'needs review'], $item->labels);
        self::assertTrue($item->draft);
        self::assertSame(strtotime('2026-11-24T09:30:00Z'), $item->updatedAt->getTimestamp());
    }

    #[Test]
    public function groupTodoFallsBackToGroupPath(): void
    {
        $item = $this->parse(Category::Todo, 'GitLab/todos.json')[1];

        self::assertSame('epic', $item->type);
        self::assertSame('acme', $item->project);
        self::assertSame('', $item->reference);
    }

    #[Test]
    public function incompleteTodoUsesBodyAndIidAndSurvivesInvalidDate(): void
    {
        $item = $this->parse(Category::Todo, 'GitLab/todos.json')[2];

        self::assertSame('Pipeline failed', $item->title);
        self::assertSame('#12', $item->reference);
        self::assertSame(0, $item->updatedAt->getTimestamp());
    }

    #[Test]
    public function mergeRequestIsParsed(): void
    {
        $items = $this->parse(Category::AssignedChange, 'GitLab/merge_requests.json');

        self::assertCount(1, $items);
        self::assertSame(Category::AssignedChange, $items[0]->category);
        self::assertSame('merge_request', $items[0]->type);
        self::assertSame('acme/website', $items[0]->project);
        self::assertSame('!15', $items[0]->reference);
        self::assertSame(['upgrade'], $items[0]->labels);
        self::assertTrue($items[0]->draft);
        self::assertSame('Self-hosted', $items[0]->connection);
    }

    #[Test]
    public function issueIsParsed(): void
    {
        $item = $this->parse(Category::AssignedIssue, 'GitLab/issues.json')[0];

        self::assertSame('issue', $item->type);
        self::assertSame('acme/sub/website', $item->project);
        self::assertSame('#3', $item->reference);
        self::assertSame('https://git.example.com/acme/sub/website/-/issues/3', $item->url);
        self::assertFalse($item->draft);
    }

    #[Test]
    public function unexpectedPayloadYieldsNoItems(): void
    {
        self::assertSame([], $this->subject->parse(
            new Query(Category::AssignedIssue, $this->subject->accountRequest(Fixture::connection(Provider::GitLab))),
            ['message' => '404 Not found'],
            Fixture::connection(Provider::GitLab)
        ));
    }

    /**
     * @return list<\Ochorocho\UnityTracker\Item\Item>
     */
    private function parse(Category $category, string $fixture): array
    {
        $connection = Fixture::connection(Provider::GitLab, 'git.example.com', label: 'Self-hosted');
        $query = new Query($category, $this->subject->accountRequest($connection));
        return $this->subject->parse($query, Fixture::payload($fixture), $connection);
    }
}
