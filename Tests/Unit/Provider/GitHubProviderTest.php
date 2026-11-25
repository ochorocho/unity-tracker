<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Provider;

use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Item\Item;
use Ochorocho\UnityTracker\Provider\GitHubProvider;
use Ochorocho\UnityTracker\Provider\Query;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GitHubProviderTest extends UnitTestCase
{
    private GitHubProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new GitHubProvider();
    }

    #[Test]
    public function requestsAuthenticateWithBearerToken(): void
    {
        $request = $this->subject->accountRequest(Fixture::connection(Provider::GitHub));

        self::assertSame('https://api.github.com/user', (string)$request->getUri());
        self::assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/vnd.github+json', $request->getHeaderLine('Accept'));
    }

    #[Test]
    public function enterpriseServerUsesApiV3Path(): void
    {
        $request = $this->subject->accountRequest(Fixture::connection(Provider::GitHub, 'ghe.example.com'));

        self::assertSame('https://ghe.example.com/api/v3/user', (string)$request->getUri());
    }

    #[Test]
    public function accountIsTheLogin(): void
    {
        self::assertSame('octocat', $this->subject->parseAccount(['login' => 'octocat']));
    }

    #[Test]
    public function queriesCoverEveryCategory(): void
    {
        $uris = [];
        foreach ($this->subject->queries(Fixture::connection(Provider::GitHub)) as $query) {
            $uris[$query->category->value] = urldecode((string)$query->request->getUri());
        }

        self::assertSame([
            'todo' => 'https://api.github.com/notifications?per_page=50',
            'reviewRequested' => 'https://api.github.com/search/issues?q=is:open is:pr archived:false review-requested:@me&sort=updated&order=desc&per_page=50',
            'assignedChange' => 'https://api.github.com/search/issues?q=is:open is:pr archived:false assignee:@me&sort=updated&order=desc&per_page=50',
            'authoredChange' => 'https://api.github.com/search/issues?q=is:open is:pr archived:false author:@me&sort=updated&order=desc&per_page=50',
            'assignedIssue' => 'https://api.github.com/search/issues?q=is:open is:issue archived:false assignee:@me&sort=updated&order=desc&per_page=50',
        ], $uris);
    }

    #[Test]
    public function pullRequestNotificationLinksToThePullRequest(): void
    {
        $item = $this->parse(Category::Todo, 'GitHub/notifications.json')[0];

        self::assertSame('pull_request', $item->type);
        self::assertSame('Fix login redirect', $item->title);
        self::assertSame('https://github.com/acme/app/pull/42', $item->url);
        self::assertSame('#42', $item->reference);
        self::assertSame('acme/app', $item->project);
        self::assertSame('review_requested', $item->reason);
    }

    #[Test]
    public function issueNotificationLinksToTheIssue(): void
    {
        $item = $this->parse(Category::Todo, 'GitHub/notifications.json')[1];

        self::assertSame('issue', $item->type);
        self::assertSame('https://github.com/acme/app/issues/9', $item->url);
    }

    #[Test]
    public function otherNotificationLinksToTheRepository(): void
    {
        $item = $this->parse(Category::Todo, 'GitHub/notifications.json')[2];

        self::assertSame('release', $item->type);
        self::assertSame('https://github.com/acme/app', $item->url);
        self::assertSame('', $item->reference);
    }

    #[Test]
    public function pullRequestSearchResultIsParsed(): void
    {
        $item = $this->parse(Category::ReviewRequested, 'GitHub/search_pulls.json')[0];

        self::assertSame(Category::ReviewRequested, $item->category);
        self::assertSame('pull_request', $item->type);
        self::assertSame('https://github.com/acme/app/pull/42', $item->url);
        self::assertSame('acme/app', $item->project);
        self::assertSame('#42', $item->reference);
        self::assertSame('octocat', $item->author);
        self::assertSame(['bug', 'security'], $item->labels);
        self::assertFalse($item->draft);
    }

    #[Test]
    public function enterpriseIssueSearchResultIsParsed(): void
    {
        $item = $this->parse(Category::AssignedIssue, 'GitHub/search_issues.json')[0];

        self::assertSame('issue', $item->type);
        self::assertSame('acme/app', $item->project);
        self::assertSame('hubot', $item->author);
        self::assertSame([], $item->labels);
    }

    /**
     * @return list<Item>
     */
    private function parse(Category $category, string $fixture): array
    {
        $connection = Fixture::connection(Provider::GitHub);
        return $this->subject->parse(new Query($category, $this->subject->accountRequest($connection)), Fixture::payload($fixture), $connection);
    }
}
