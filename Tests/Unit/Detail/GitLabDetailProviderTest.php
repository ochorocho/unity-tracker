<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use GuzzleHttp\Psr7\Response;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\GitLabDetailProvider;
use Ochorocho\UnityTracker\Detail\ItemAction;
use Ochorocho\UnityTracker\Detail\ItemDetail;
use Ochorocho\UnityTracker\Detail\ItemReference;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GitLabDetailProviderTest extends UnitTestCase
{
    private GitLabDetailProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new GitLabDetailProvider();
    }

    #[Test]
    public function detailIsOneGraphqlQueryPlusTheTokenScopes(): void
    {
        $requests = $this->subject->detailRequests($this->reference('merge_request'), Fixture::connection(Provider::GitLab, 'git.example.com'));

        self::assertSame('https://git.example.com/api/graphql', (string)$requests['detail']->getUri());
        self::assertSame('Bearer secret-token', $requests['detail']->getHeaderLine('Authorization'));
        $body = json_decode((string)$requests['detail']->getBody(), true);
        self::assertSame(['fullPath' => 'acme/app', 'iid' => '12', 'before' => null], $body['variables']);
        self::assertStringContainsString('mergeRequest(iid: $iid)', $body['query']);
        self::assertSame('https://git.example.com/api/v4/personal_access_tokens/self', (string)$requests['scopes']->getUri());
    }

    #[Test]
    public function mergeRequestIsParsedWithItsPermissions(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');

        self::assertSame('Fix login redirect', $detail->title);
        self::assertSame(ItemDetail::STATE_OPEN, $detail->state);
        self::assertSame([['id' => '7', 'name' => 'joe']], $detail->assignees);
        self::assertSame(['id' => '42', 'name' => 'me'], $detail->viewer);
        self::assertSame(['501', '504', '505', '503'], array_column($detail->comments(), 'id'), 'system notes are no comments');
        self::assertSame([false, false, true, true], array_column($detail->comments(), 'canEdit'));
        self::assertSame('Thanks!', $detail->comment('503')['body'] ?? null);
        self::assertSame('eyJpZCI6IjUwMCJ9', $detail->olderCursor);
        self::assertSame([['name' => 'feature', 'color' => '#428bca'], ['name' => 'evil', 'color' => '']], $detail->addableLabels());
        self::assertSame([
            ItemAction::COMMENT, ItemAction::EDIT, ItemAction::CLOSE, ItemAction::ASSIGN_ME, ItemAction::ADD_LABEL,
            ItemAction::REMOVE_LABEL, ItemAction::ADD_ASSIGNEE, ItemAction::REMOVE_ASSIGNEE, ItemAction::SET_MILESTONE, ItemAction::LOCK,
            ItemAction::ADD_REVIEWER, ItemAction::REMOVE_REVIEWER, ItemAction::UNSUBSCRIBE, ItemAction::APPROVE, ItemAction::MERGE, ItemAction::UPDATE_BRANCH, ItemAction::MARK_DRAFT, ItemAction::MARK_DONE, ItemAction::EDIT_COMMENT,
            ItemAction::DELETE_COMMENT, ItemAction::REPLY, ItemAction::RESOLVE, ItemAction::UNRESOLVE, ItemAction::REACT, ItemAction::TOGGLE_TASK,
        ], $detail->actions);
    }

    #[Test]
    public function awardEmojiBecomeReactions(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');

        self::assertSame([
            ['name' => 'thumbsup', 'emoji' => '👍', 'count' => 2, 'reacted' => true],
            ['name' => 'flag_de', 'emoji' => '🇩🇪', 'count' => 1, 'reacted' => false],
        ], $detail->reactions, 'the eight choices first, invalid names dropped');
        self::assertSame('gid://gitlab/MergeRequest/9012', $detail->subject);
        self::assertTrue($detail->canReact);
        $comment = $detail->comment('501');
        self::assertNotNull($comment);
        self::assertSame([['name' => 'tada', 'emoji' => '🎉', 'count' => 1, 'reacted' => false]], $comment['reactions']);
        self::assertSame(['gid://gitlab/Note/501', true], [$comment['subject'], $comment['canReact']]);
    }

    #[Test]
    public function issueAwardsComeFromTheRestApi(): void
    {
        $reference = $this->reference('issue', '');
        $requests = $this->subject->detailRequests($reference, Fixture::connection(Provider::GitLab));
        self::assertSame('https://gitlab.com/api/v4/projects/acme%2Fapp/issues/9/award_emoji?per_page=100', (string)$requests['awards']->getUri());

        $detail = $this->subject->parseDetail($reference, [
            'detail' => new Response(200, [], Fixture::json('GitLab/graphql_issue_closed.json')),
            'scopes' => null,
            'awards' => new Response(200, [], '[{"name": "rocket", "user": {"username": "me"}}, {"name": "custom", "user": {"username": "joe"}}]'),
        ]);
        self::assertSame([
            ['name' => 'rocket', 'emoji' => '🚀', 'count' => 1, 'reacted' => true],
            ['name' => 'custom', 'emoji' => ':custom:', 'count' => 1, 'reacted' => false],
        ], $detail->reactions);
        self::assertSame('gid://gitlab/Issue/3456', $detail->subject);
    }

    #[Test]
    public function discussionsBecomeEventsAndThreads(): void
    {
        $timeline = $this->parse('merge_request', 'GitLab/graphql_merge_request.json')->timeline;

        self::assertSame(['thread', 'event', 'thread', 'thread'], array_column($timeline, 'type'));
        self::assertSame(['jane', '<p>added 1 commit</p>'], [$timeline[1]['author'], $timeline[1]['html']]);
        $thread = $timeline[2];
        self::assertSame(['ccc333', 'ccc333', 'src/Login.php', 12], [$thread['id'], $thread['replyTo'], $thread['path'], $thread['line']]);
        self::assertTrue($thread['resolvable'] && $thread['canResolve'] && $thread['canReply']);
        self::assertFalse($timeline[0]['canResolve'], 'single comments cannot be resolved');
        self::assertSame(['joe', 'me'], array_column($thread['comments'], 'author'));
    }

    #[Test]
    public function closedIssueOffersWhatThePermissionsAllow(): void
    {
        $detail = $this->parse('issue', 'GitLab/graphql_issue_closed.json', todo: '');

        self::assertSame([ItemAction::COMMENT, ItemAction::REOPEN, ItemAction::SUBSCRIBE, ItemAction::REACT], $detail->actions);
        self::assertSame(['2026-12-24', true, true], [$detail->dueDate, $detail->confidential, $detail->locked]);
        self::assertSame(['id' => '', 'title' => ''], $detail->milestone);
    }

    #[Test]
    public function mergeStateIsParsed(): void
    {
        $merge = $this->parse('merge_request', 'GitLab/graphql_merge_request.json')->merge;

        self::assertNotNull($merge);
        self::assertSame(['failed', 'Failed', 'https://gitlab.com/acme/app/-/pipelines/77'], [$merge->pipeline, $merge->pipelineText, $merge->pipelineUrl]);
        self::assertSame([2, 1, 1], [$merge->approvalsRequired, $merge->approvalsLeft, $merge->getApprovalsGiven()]);
        self::assertSame(['ci_must_pass', 'not_approved'], $merge->blockers, 'failed checks only');
        self::assertSame([true, false], [$merge->squash, $merge->squashLocked]);
        self::assertTrue($merge->deleteBranch, 'the project default applies');
        self::assertSame(str_repeat('b', 40), $merge->headSha);
        self::assertNull($this->parse('issue', 'GitLab/graphql_issue_closed.json', todo: '')->merge);
    }

    #[Test]
    public function mergeSendsTheChosenOptionsAndTheCommit(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');
        $connection = Fixture::connection(Provider::GitLab);
        $options = ['method' => '', 'squash' => '', 'deleteBranch' => '1', 'sha' => str_repeat('b', 40)];

        $merge = $this->subject->actionRequest($detail, ItemAction::MERGE, $options, $connection);
        self::assertSame(['sha' => str_repeat('b', 40), 'should_remove_source_branch' => true, 'squash' => false], json_decode((string)$merge->getBody(), true));
        $auto = json_decode((string)$this->subject->actionRequest($detail, ItemAction::ENABLE_AUTO_MERGE, $options, $connection)->getBody(), true);
        self::assertStringContainsString('strategy: MERGE_WHEN_CHECKS_PASS', $auto['query']);
        self::assertSame(['path' => 'acme/app', 'iid' => '12', 'sha' => str_repeat('b', 40), 'squash' => false, 'remove' => true], $auto['variables']);
        $rebase = $this->subject->actionRequest($detail, ItemAction::UPDATE_BRANCH, [], $connection);
        self::assertSame('PUT https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12/rebase', $rebase->getMethod() . ' ' . $rebase->getUri());
        $draft = json_decode((string)$this->subject->actionRequest($detail, ItemAction::MARK_DRAFT, [], $connection)->getBody(), true);
        self::assertStringContainsString('mergeRequestSetDraft(', $draft['query']);
        self::assertTrue($draft['variables']['value']);
    }

    #[Test]
    public function sidebarIsParsed(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');

        self::assertSame([['name' => 'ann', 'state' => '', 'removable' => true]], $detail->reviewers);
        self::assertSame(['id' => '31', 'title' => 'v2.0'], $detail->milestone);
        self::assertSame([['id' => '31', 'title' => 'v2.0'], ['id' => '32', 'title' => 'v2.1']], $detail->availableMilestones);
        self::assertSame(['jane', 'joe', 'me'], $detail->participants);
        self::assertTrue($detail->subscribed);
        self::assertFalse($detail->locked);
    }

    #[Test]
    public function sidebarChangesAreMutationsOfTheItem(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');
        $connection = Fixture::connection(Provider::GitLab);
        $mutation = fn(string $action, array $parameters = []): array => json_decode(
            (string)$this->subject->actionRequest($detail, $action, $parameters, $connection)->getBody(),
            true
        );

        $assignee = $mutation(ItemAction::ADD_ASSIGNEE, ['user' => 'jane']);
        self::assertStringContainsString('mergeRequestSetAssignees(input: {projectPath: $path, iid: $iid, assigneeUsernames: $value, operationMode: APPEND})', $assignee['query']);
        self::assertSame(['path' => 'acme/app', 'iid' => '12', 'value' => ['jane']], $assignee['variables']);
        self::assertStringContainsString('reviewerUsernames: $value, operationMode: REMOVE', $mutation(ItemAction::REMOVE_REVIEWER, ['user' => 'ann'])['query']);
        $lock = $mutation(ItemAction::LOCK);
        self::assertStringContainsString('mergeRequestSetLocked(', $lock['query']);
        self::assertTrue($lock['variables']['value']);
        self::assertFalse($mutation(ItemAction::UNSUBSCRIBE)['variables']['value']);

        $milestone = $this->subject->actionRequest($detail, ItemAction::SET_MILESTONE, ['milestone' => ''], $connection);
        self::assertSame('PUT https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12', $milestone->getMethod() . ' ' . $milestone->getUri());
        self::assertSame('{"milestone_id":0}', (string)$milestone->getBody());

        $issue = $this->parse('issue', 'GitLab/graphql_issue_closed.json', todo: '');
        $dueDate = json_decode((string)$this->subject->actionRequest($issue, ItemAction::SET_DUE_DATE, ['dueDate' => ''], $connection)->getBody(), true);
        self::assertStringContainsString('issueSetDueDate(input: {projectPath: $path, iid: $iid, dueDate: $value})', $dueDate['query']);
        self::assertNull($dueDate['variables']['value']);
        $confidential = json_decode((string)$this->subject->actionRequest($issue, ItemAction::SET_CONFIDENTIAL, ['confidential' => ''], $connection)->getBody(), true);
        self::assertStringContainsString('issueSetConfidential(', $confidential['query']);
        self::assertFalse($confidential['variables']['value']);
    }

    #[Test]
    public function tokenWithoutApiScopeIsReadOnly(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json', scopes: '{"scopes": ["read_api"]}');

        self::assertSame([], $detail->actions);
        self::assertSame('api', $detail->missingScope);
        self::assertNotContains(true, array_column($detail->comments(), 'canEdit'));
        self::assertNotContains(true, array_column($detail->comments(), 'canDelete'));
        self::assertNotContains(true, array_column($detail->timeline, 'canReply'));
        self::assertNotContains(true, array_column($detail->comments(), 'canReact'));
        self::assertFalse($detail->canReact);
    }

    #[Test]
    public function unknownScopesDoNotHideActions(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json', scopes: null);

        self::assertNotSame([], $detail->actions);
    }

    #[Test]
    public function graphqlErrorWithoutDataFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->subject->parseDetail($this->reference('issue'), ['detail' => new Response(200, [], '{"errors": [{"message": "Field missing"}]}'), 'scopes' => null]);
    }

    #[Test]
    public function actionsUseTheRestApi(): void
    {
        $detail = $this->parse('merge_request', 'GitLab/graphql_merge_request.json');
        $connection = Fixture::connection(Provider::GitLab);
        $request = fn(string $action, array $parameters = []) => $this->subject->actionRequest($detail, $action, $parameters, $connection);

        $assign = $request(ItemAction::ASSIGN_ME);
        self::assertSame('PUT https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12', $assign->getMethod() . ' ' . $assign->getUri());
        self::assertSame('{"assignee_ids":[7,42]}', (string)$assign->getBody());
        self::assertSame('secret-token', $assign->getHeaderLine('PRIVATE-TOKEN'));
        self::assertSame('{"add_labels":"feature"}', (string)$request(ItemAction::ADD_LABEL, ['label' => 'feature'])->getBody());
        self::assertSame('https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12/approve', (string)$request(ItemAction::APPROVE)->getUri());
        self::assertSame('https://gitlab.com/api/v4/todos/55/mark_as_done', (string)$request(ItemAction::MARK_DONE)->getUri());
        self::assertSame('{"body":"Thanks"}', (string)$request(ItemAction::COMMENT, ['body' => 'Thanks'])->getBody());
        $editComment = $request(ItemAction::EDIT_COMMENT, ['comment' => '503', 'body' => 'Thanks a lot!']);
        self::assertSame('PUT https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12/notes/503', $editComment->getMethod() . ' ' . $editComment->getUri());
        self::assertSame('{"body":"Thanks a lot!"}', (string)$editComment->getBody());
        $delete = $request(ItemAction::DELETE_COMMENT, ['comment' => '503']);
        self::assertSame('DELETE https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12/notes/503', $delete->getMethod() . ' ' . $delete->getUri());
        $reply = $request(ItemAction::REPLY, ['thread' => 'ccc333', 'replyTo' => 'ccc333', 'body' => 'Done']);
        self::assertSame('POST https://gitlab.com/api/v4/projects/acme%2Fapp/merge_requests/12/discussions/ccc333/notes', $reply->getMethod() . ' ' . $reply->getUri());
        $resolve = $request(ItemAction::RESOLVE, ['thread' => 'ccc333', 'replyTo' => 'ccc333']);
        self::assertSame('https://gitlab.com/api/graphql', (string)$resolve->getUri());
        self::assertSame(['id' => 'gid://gitlab/Discussion/ccc333', 'resolve' => true], json_decode((string)$resolve->getBody(), true)['variables']);
        self::assertFalse(json_decode((string)$request(ItemAction::UNRESOLVE, ['thread' => 'ccc333', 'replyTo' => 'ccc333'])->getBody(), true)['variables']['resolve']);
        $react = json_decode((string)$request(ItemAction::REACT, ['subject' => 'gid://gitlab/Note/501', 'emoji' => 'tada', 'remove' => ''])->getBody(), true);
        self::assertStringContainsString('awardEmojiToggle', $react['query']);
        self::assertSame(['id' => 'gid://gitlab/Note/501', 'name' => 'tada'], $react['variables']);
    }

    #[Test]
    public function previewIsRenderedByGitLabInTheProject(): void
    {
        $request = $this->subject->previewRequest($this->reference('issue'), Fixture::connection(Provider::GitLab), 'Fixes #3');

        self::assertSame('POST https://gitlab.com/api/v4/markdown', $request->getMethod() . ' ' . $request->getUri());
        self::assertSame(['text' => 'Fixes #3', 'gfm' => true, 'project' => 'acme/app'], json_decode((string)$request->getBody(), true));
        self::assertSame('<p>Fixes</p>', $this->subject->parsePreview(new Response(200, [], '{"html": "<p>Fixes</p>"}')));
    }

    #[Test]
    public function suggestionsSearchUsersIssuesAndMergeRequests(): void
    {
        $connection = Fixture::connection(Provider::GitLab);
        $reference = $this->reference('issue');

        $users = $this->subject->suggestionRequest($reference, $connection, 'user', 'ja');
        self::assertSame(['fullPath' => 'acme/app', 'search' => 'ja'], json_decode((string)$users?->getBody(), true)['variables']);
        self::assertSame(
            [['value' => 'jane', 'label' => 'jane Jane Doe']],
            $this->subject->parseSuggestions('user', new Response(200, [], '{"data": {"project": {"autocompleteUsers": [{"username": "jane", "name": "Jane Doe"}]}}}'))
        );
        self::assertSame(
            'https://gitlab.com/api/v4/projects/acme%2Fapp/issues?per_page=10&order_by=updated_at&search=login%20bug',
            (string)$this->subject->suggestionRequest($reference, $connection, 'issue', 'login bug')?->getUri()
        );
        self::assertSame(
            'https://gitlab.com/api/v4/projects/acme/app/merge_requests?per_page=10&order_by=updated_at&iids[]=12',
            rawurldecode((string)$this->subject->suggestionRequest($reference, $connection, 'mergeRequest', '12')?->getUri())
        );
        self::assertSame(
            [['value' => '12', 'label' => '12 Fix login']],
            $this->subject->parseSuggestions('mergeRequest', new Response(200, [], '[{"iid": 12, "title": "Fix login"}]'))
        );
        self::assertNull($this->subject->suggestionRequest($reference, $connection, 'emoji', 'tada'));
    }

    private function reference(string $type, string $todo = '55'): ItemReference
    {
        return ItemReference::fromArray(['connection' => 1, 'type' => $type, 'project' => 'acme/app', 'number' => $type === 'issue' ? 9 : 12, 'todo' => $todo]);
    }

    private function parse(string $type, string $fixture, ?string $scopes = '{"scopes": ["api"]}', string $todo = '55'): ItemDetail
    {
        return $this->subject->parseDetail($this->reference($type, $todo), [
            'detail' => new Response(200, [], Fixture::json($fixture)),
            'scopes' => $scopes !== null ? new Response(200, [], $scopes) : null,
        ]);
    }
}
