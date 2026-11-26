<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use GuzzleHttp\Psr7\Response;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\GitHubDetailProvider;
use Ochorocho\UnityTracker\Detail\ItemAction;
use Ochorocho\UnityTracker\Detail\ItemDetail;
use Ochorocho\UnityTracker\Detail\ItemReference;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GitHubDetailProviderTest extends UnitTestCase
{
    private GitHubDetailProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new GitHubDetailProvider();
    }

    #[Test]
    public function detailQueryGoesToTheGraphqlEndpoint(): void
    {
        $github = $this->subject->detailRequests($this->reference(), Fixture::connection(Provider::GitHub));
        $enterprise = $this->subject->detailRequests($this->reference(), Fixture::connection(Provider::GitHub, 'ghe.example.com'));

        self::assertSame('https://api.github.com/graphql', (string)$github['detail']->getUri());
        self::assertSame('https://ghe.example.com/api/graphql', (string)$enterprise['detail']->getUri());
        self::assertSame(['owner' => 'acme', 'name' => 'app', 'number' => 42, 'before' => null], json_decode((string)$github['detail']->getBody(), true)['variables']);
    }

    #[Test]
    public function olderTimelinePagesAreRequestedWithTheCursor(): void
    {
        $request = $this->subject->detailRequests($this->reference(), Fixture::connection(Provider::GitHub), 'Y3Vyc29y');

        self::assertSame('Y3Vyc29y', json_decode((string)$request['detail']->getBody(), true)['variables']['before']);
        self::assertSame('Y3Vyc29y', $this->parse(before: 'Y3Vyc29y')->page);
    }

    #[Test]
    public function pullRequestIsParsedWithItsPermissions(): void
    {
        $detail = $this->parse();

        self::assertSame(ItemDetail::STATE_OPEN, $detail->state);
        self::assertSame('mergeable', $detail->mergeStatus);
        self::assertSame([['name' => 'needs review', 'color' => '#0e8a16']], $detail->labels);
        self::assertTrue($detail->isAssignedToViewer());
        self::assertSame([
            ItemAction::COMMENT, ItemAction::EDIT, ItemAction::CLOSE, ItemAction::UNASSIGN_ME, ItemAction::ADD_LABEL,
            ItemAction::REMOVE_LABEL, ItemAction::APPROVE, ItemAction::REQUEST_CHANGES, ItemAction::MERGE, ItemAction::MARK_DONE,
            ItemAction::EDIT_COMMENT, ItemAction::DELETE_COMMENT, ItemAction::REPLY, ItemAction::RESOLVE, ItemAction::UNRESOLVE,
            ItemAction::REACT,
        ], $detail->actions);
        self::assertSame(['9001', 'review-8001', 'review-8002', '9002'], array_column($detail->comments(), 'id'));
        self::assertFalse($detail->comment('9001')['canEdit'] ?? true);
        self::assertSame('Fixed', $detail->comment('9002')['body'] ?? null);
        self::assertSame('', $detail->olderCursor);
    }

    #[Test]
    public function timelineHasEventsCommentsAndReviewThreadsAfterTheirReview(): void
    {
        $timeline = $this->parse()->timeline;

        self::assertSame(
            ['event', 'thread', 'event', 'thread', 'thread', 'event'],
            array_column($timeline, 'type'),
            'empty "commented" reviews and unknown items are left out, threads of reviews on other pages too'
        );
        self::assertSame(['labeled', 'needs review'], [$timeline[0]['event'], $timeline[0]['subject']]);
        self::assertSame(['committed', '1a2b3c4 Fix the redirect', 'octocat'], [$timeline[2]['event'], $timeline[2]['subject'], $timeline[2]['author']]);
        self::assertSame('approved', $timeline[5]['event']);

        $thread = $timeline[3];
        self::assertSame('PRRT_kwDOabc', $thread['id']);
        self::assertSame(['src/Login.php', 12, '8001'], [$thread['path'], $thread['line'], $thread['replyTo']]);
        self::assertTrue($thread['resolvable'] && $thread['canResolve'] && $thread['canReply']);
        self::assertSame([false, true], array_column($thread['comments'], 'canDelete'));
        self::assertFalse($timeline[1]['canReply'], 'issue comments have no replies on GitHub');
    }

    #[Test]
    public function reactionGroupsBecomeReactions(): void
    {
        $detail = $this->parse();

        self::assertSame([
            ['name' => 'tada', 'emoji' => '🎉', 'count' => 3, 'reacted' => true],
            ['name' => 'heart', 'emoji' => '❤️', 'count' => 1, 'reacted' => false],
        ], $detail->reactions, 'empty and unknown groups are left out');
        self::assertSame(['PR_kwDO42', true], [$detail->subject, $detail->canReact]);
        $comment = $detail->comment('9001');
        self::assertNotNull($comment);
        self::assertSame(['IC_9001', true, ['eyes']], [$comment['subject'], $comment['canReact'], array_column($comment['reactions'], 'name')]);
        self::assertFalse($detail->comment('review-8001')['canReact'] ?? true);
    }

    #[Test]
    public function reactionsAreAddedAndRemovedThroughGraphql(): void
    {
        $detail = $this->parse();
        $connection = Fixture::connection(Provider::GitHub);

        $add = json_decode((string)$this->subject->actionRequest($detail, ItemAction::REACT, ['subject' => 'IC_9001', 'emoji' => 'tada', 'remove' => ''], $connection)->getBody(), true);
        self::assertStringContainsString('addReaction(', $add['query']);
        self::assertSame(['id' => 'IC_9001', 'content' => 'HOORAY'], $add['variables']);
        $remove = json_decode((string)$this->subject->actionRequest($detail, ItemAction::REACT, ['subject' => 'PR_kwDO42', 'emoji' => 'thumbsup', 'remove' => '1'], $connection)->getBody(), true);
        self::assertStringContainsString('removeReaction(', $remove['query']);
        self::assertSame('THUMBS_UP', $remove['variables']['content']);
    }

    #[Test]
    public function reviewWithTextIsAThread(): void
    {
        $detail = $this->parse(static function (array $payload): array {
            $payload['data']['repository']['target']['pullTimeline']['nodes'][3]['body'] = 'Some remarks';
            return $payload;
        });

        $review = $detail->timeline[3];
        self::assertSame(['thread', 'commented', 'pullReview-700'], [$review['type'], $review['review'], $review['id']]);
        self::assertFalse($review['comments'][0]['canDelete'], 'submitted reviews cannot be deleted');
    }

    #[Test]
    public function ownPullRequestCannotBeReviewedAndReadAccessCannotMerge(): void
    {
        $detail = $this->parse(static function (array $payload): array {
            $payload['data']['repository']['target']['viewerDidAuthor'] = true;
            $payload['data']['repository']['viewerPermission'] = 'READ';
            return $payload;
        });

        self::assertFalse($detail->allows(ItemAction::APPROVE));
        self::assertFalse($detail->allows(ItemAction::REQUEST_CHANGES));
        self::assertFalse($detail->allows(ItemAction::MERGE));
    }

    #[Test]
    public function classicTokenWithoutRepoScopeIsReadOnly(): void
    {
        $detail = $this->parse(scopes: 'notifications, read:user');

        self::assertSame([], $detail->actions);
        self::assertSame('repo', $detail->missingScope);
    }

    #[Test]
    public function actionsUseTheRestApi(): void
    {
        $detail = $this->parse();
        $connection = Fixture::connection(Provider::GitHub);
        $request = fn(string $action, array $parameters = []) => $this->subject->actionRequest($detail, $action, $parameters, $connection);

        $removeLabel = $request(ItemAction::REMOVE_LABEL, ['label' => 'needs review']);
        self::assertSame('DELETE https://api.github.com/repos/acme/app/issues/42/labels/needs%20review', $removeLabel->getMethod() . ' ' . $removeLabel->getUri());
        $unassign = $request(ItemAction::UNASSIGN_ME);
        self::assertSame('DELETE', $unassign->getMethod());
        self::assertSame('{"assignees":["octocat"]}', (string)$unassign->getBody());
        self::assertSame('{"event":"REQUEST_CHANGES","body":"Please test"}', (string)$request(ItemAction::REQUEST_CHANGES, ['body' => 'Please test'])->getBody());
        self::assertSame('PUT https://api.github.com/repos/acme/app/pulls/42/merge', $request(ItemAction::MERGE)->getMethod() . ' ' . $request(ItemAction::MERGE)->getUri());
        self::assertSame('PATCH https://api.github.com/notifications/threads/7', $request(ItemAction::MARK_DONE)->getMethod() . ' ' . $request(ItemAction::MARK_DONE)->getUri());
        $editComment = $request(ItemAction::EDIT_COMMENT, ['comment' => '9002', 'body' => 'Fixed in 1a2b3c']);
        self::assertSame('PATCH https://api.github.com/repos/acme/app/issues/comments/9002', $editComment->getMethod() . ' ' . $editComment->getUri());
        $editReviewComment = $request(ItemAction::EDIT_COMMENT, ['comment' => 'review-8002', 'body' => 'Oops, fixed']);
        self::assertSame('PATCH https://api.github.com/repos/acme/app/pulls/comments/8002', $editReviewComment->getMethod() . ' ' . $editReviewComment->getUri());
        $delete = $request(ItemAction::DELETE_COMMENT, ['comment' => 'review-8002']);
        self::assertSame('DELETE https://api.github.com/repos/acme/app/pulls/comments/8002', $delete->getMethod() . ' ' . $delete->getUri());
        $reply = $request(ItemAction::REPLY, ['thread' => 'PRRT_kwDOabc', 'replyTo' => '8001', 'body' => 'Done']);
        self::assertSame('POST https://api.github.com/repos/acme/app/pulls/42/comments/8001/replies', $reply->getMethod() . ' ' . $reply->getUri());
        self::assertSame('{"body":"Done"}', (string)$reply->getBody());
    }

    #[Test]
    public function threadsAreResolvedThroughGraphql(): void
    {
        $detail = $this->parse();
        $connection = Fixture::connection(Provider::GitHub);

        $resolve = $this->subject->actionRequest($detail, ItemAction::RESOLVE, ['thread' => 'PRRT_kwDOabc', 'replyTo' => '8001'], $connection);
        $body = json_decode((string)$resolve->getBody(), true);
        self::assertSame('POST https://api.github.com/graphql', $resolve->getMethod() . ' ' . $resolve->getUri());
        self::assertStringContainsString('resolveReviewThread', $body['query']);
        self::assertSame(['id' => 'PRRT_kwDOabc'], $body['variables']);
        $unresolve = $this->subject->actionRequest($detail, ItemAction::UNRESOLVE, ['thread' => 'PRRT_kwDOabc', 'replyTo' => '8001'], $connection);
        self::assertStringContainsString('unresolveReviewThread', json_decode((string)$unresolve->getBody(), true)['query']);
    }

    #[Test]
    public function previewIsRenderedByGitHubInTheRepository(): void
    {
        $request = $this->subject->previewRequest($this->reference(), Fixture::connection(Provider::GitHub), 'Fixes #3');

        self::assertSame('POST https://api.github.com/markdown', $request->getMethod() . ' ' . $request->getUri());
        self::assertSame(['text' => 'Fixes #3', 'mode' => 'gfm', 'context' => 'acme/app'], json_decode((string)$request->getBody(), true));
        self::assertSame('<p>Fixes</p>', $this->subject->parsePreview(new Response(200, ['Content-Type' => 'text/html'], '<p>Fixes</p>')));
    }

    #[Test]
    public function suggestionsSearchMentionableUsersAndIssues(): void
    {
        $connection = Fixture::connection(Provider::GitHub);

        $users = $this->subject->suggestionRequest($this->reference(), $connection, 'user', 'oct');
        self::assertSame(['owner' => 'acme', 'name' => 'app', 'search' => 'oct'], json_decode((string)$users?->getBody(), true)['variables']);
        self::assertSame(
            'https://api.github.com/search/issues?per_page=10&sort=updated&q=repo%3Aacme%2Fapp%20login',
            (string)$this->subject->suggestionRequest($this->reference(), $connection, 'issue', 'login')?->getUri()
        );
        self::assertSame(
            [['value' => '42', 'label' => '42 Fix login']],
            $this->subject->parseSuggestions('issue', new Response(200, [], '{"items": [{"number": 42, "title": "Fix login"}]}'))
        );
        self::assertNull($this->subject->suggestionRequest($this->reference(), $connection, 'mergeRequest', '1'));
    }

    private function reference(): ItemReference
    {
        return ItemReference::fromArray(['connection' => 1, 'type' => 'pull_request', 'project' => 'acme/app', 'number' => 42, 'todo' => '7']);
    }

    /**
     * @param (callable(array<mixed>): array<mixed>)|null $change
     */
    private function parse(?callable $change = null, string $scopes = 'repo, notifications', string $before = ''): ItemDetail
    {
        $payload = Fixture::payload('GitHub/graphql_pull_request.json');
        if ($change !== null) {
            $payload = $change($payload);
        }
        return $this->subject->parseDetail($this->reference(), [
            'detail' => new Response(200, ['X-OAuth-Scopes' => $scopes], (string)json_encode($payload)),
        ], $before);
    }
}
