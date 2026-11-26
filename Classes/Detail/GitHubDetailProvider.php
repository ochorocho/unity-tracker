<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use GuzzleHttp\Psr7\Request;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Provider\Payload;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Details through one GraphQL query (bodyHTML and viewerCan* permissions), actions through the REST API.
 * Classic tokens report their scopes in X-OAuth-Scopes; write actions need "repo" (or "public_repo").
 */
final readonly class GitHubDetailProvider implements DetailProviderInterface
{
    public const WRITE_SCOPE = 'repo';

    private const QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $number: Int!, $before: String) {
          viewer { login }
          repository(owner: $owner, name: $name) {
            viewerPermission
            labels(first: 100) { nodes { name color } }
            target: issueOrPullRequest(number: $number) {
              ... on Issue {
                %1$s
                issueTimeline: timelineItems(last: 40, before: $before, itemTypes: [%2$s]) {
                  pageInfo { hasPreviousPage startCursor }
                  nodes { __typename %3$s }
                }
              }
              ... on PullRequest {
                %1$s
                pullTimeline: timelineItems(last: 40, before: $before, itemTypes: [%2$s, %4$s]) {
                  pageInfo { hasPreviousPage startCursor }
                  nodes { __typename %3$s %5$s }
                }
                reviewThreads(last: 100) {
                  nodes {
                    id isResolved path line viewerCanReply viewerCanResolve viewerCanUnresolve
                    comments(first: 50) { nodes { databaseId body bodyHTML createdAt url viewerCanUpdate viewerCanDelete author { login } pullRequestReview { id } } }
                  }
                }
                isDraft merged mergeable baseRefName headRefName reviewDecision
                viewerLatestReview { state }
              }
            }
          }
        }
        GRAPHQL;

    private const COMMON_FIELDS = <<<'GRAPHQL'
        title state url body bodyHTML createdAt
        author { login }
        labels(first: 50) { nodes { name color } }
        assignees(first: 20) { nodes { login } }
        viewerCanUpdate viewerDidAuthor viewerCanClose viewerCanReopen viewerCanLabel viewerCanAssign
        GRAPHQL;

    private const ISSUE_ITEM_TYPES = 'ISSUE_COMMENT, LABELED_EVENT, UNLABELED_EVENT, ASSIGNED_EVENT, UNASSIGNED_EVENT, CLOSED_EVENT, '
        . 'REOPENED_EVENT, RENAMED_TITLE_EVENT, MILESTONED_EVENT, DEMILESTONED_EVENT, CROSS_REFERENCED_EVENT, LOCKED_EVENT, UNLOCKED_EVENT';

    private const PULL_ITEM_TYPES = 'MERGED_EVENT, REVIEW_REQUESTED_EVENT, REVIEW_REQUEST_REMOVED_EVENT, READY_FOR_REVIEW_EVENT, '
        . 'CONVERT_TO_DRAFT_EVENT, HEAD_REF_FORCE_PUSHED_EVENT, PULL_REQUEST_COMMIT, PULL_REQUEST_REVIEW';

    /** Members of IssueTimelineItems, which PullRequestTimelineItems has as well */
    private const ISSUE_ITEM_FIELDS = <<<'GRAPHQL'
        ... on IssueComment { databaseId body bodyHTML createdAt viewerCanUpdate viewerCanDelete author { login } }
        ... on LabeledEvent { id createdAt actor { login } label { name } }
        ... on UnlabeledEvent { id createdAt actor { login } label { name } }
        ... on AssignedEvent { id createdAt actor { login } assignee { ... on User { login } ... on Bot { login } } }
        ... on UnassignedEvent { id createdAt actor { login } assignee { ... on User { login } ... on Bot { login } } }
        ... on ClosedEvent { id createdAt actor { login } stateReason }
        ... on ReopenedEvent { id createdAt actor { login } }
        ... on RenamedTitleEvent { id createdAt actor { login } currentTitle }
        ... on MilestonedEvent { id createdAt actor { login } milestoneTitle }
        ... on DemilestonedEvent { id createdAt actor { login } milestoneTitle }
        ... on CrossReferencedEvent { id createdAt actor { login } source {
          ... on Issue { number title repository { nameWithOwner } }
          ... on PullRequest { number title repository { nameWithOwner } }
        } }
        ... on LockedEvent { id createdAt actor { login } }
        ... on UnlockedEvent { id createdAt actor { login } }
        GRAPHQL;

    /** No team fields: they need the read:org scope, and one missing scope fails the whole query. */
    private const PULL_ITEM_FIELDS = <<<'GRAPHQL'
        ... on MergedEvent { id createdAt actor { login } }
        ... on ReviewRequestedEvent { id createdAt actor { login } requestedReviewer { ... on User { login } ... on Bot { login } } }
        ... on ReviewRequestRemovedEvent { id createdAt actor { login } requestedReviewer { ... on User { login } ... on Bot { login } } }
        ... on ReadyForReviewEvent { id createdAt actor { login } }
        ... on ConvertToDraftEvent { id createdAt actor { login } }
        ... on HeadRefForcePushedEvent { id createdAt actor { login } }
        ... on PullRequestCommit { id commit { abbreviatedOid messageHeadline committedDate author { name user { login } } } }
        ... on PullRequestReview { id databaseId state body bodyHTML createdAt viewerCanUpdate author { login } }
        GRAPHQL;

    /** Event typename → label key suffix item.event.* */
    private const EVENTS = [
        'LabeledEvent' => 'labeled', 'UnlabeledEvent' => 'unlabeled', 'AssignedEvent' => 'assigned', 'UnassignedEvent' => 'unassigned',
        'ClosedEvent' => 'closed', 'ReopenedEvent' => 'reopened', 'RenamedTitleEvent' => 'renamed', 'MilestonedEvent' => 'milestoned',
        'DemilestonedEvent' => 'demilestoned', 'CrossReferencedEvent' => 'referenced', 'LockedEvent' => 'locked', 'UnlockedEvent' => 'unlocked',
        'MergedEvent' => 'merged', 'ReviewRequestedEvent' => 'reviewRequested', 'ReviewRequestRemovedEvent' => 'reviewRequestRemoved',
        'ReadyForReviewEvent' => 'readyForReview', 'ConvertToDraftEvent' => 'convertedToDraft', 'HeadRefForcePushedEvent' => 'forcePushed',
    ];

    private const RESOLVE_MUTATION = <<<'GRAPHQL'
        mutation($id: ID!) { resolveReviewThread(input: {threadId: $id}) { thread { isResolved } } }
        GRAPHQL;

    private const UNRESOLVE_MUTATION = <<<'GRAPHQL'
        mutation($id: ID!) { unresolveReviewThread(input: {threadId: $id}) { thread { isResolved } } }
        GRAPHQL;

    private const USERS_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $search: String) {
          repository(owner: $owner, name: $name) { mentionableUsers(query: $search, first: 10) { nodes { login name } } }
        }
        GRAPHQL;

    private const WRITE_PERMISSIONS = ['WRITE', 'MAINTAIN', 'ADMIN'];

    public function detailRequests(ItemReference $reference, Connection $connection, string $before = ''): array
    {
        [$owner, $name] = $this->repository($reference);
        return [
            'detail' => $this->graphql($connection, sprintf(
                self::QUERY,
                self::COMMON_FIELDS,
                self::ISSUE_ITEM_TYPES,
                self::ISSUE_ITEM_FIELDS,
                self::PULL_ITEM_TYPES,
                self::PULL_ITEM_FIELDS
            ), ['owner' => $owner, 'name' => $name, 'number' => $reference->number, 'before' => $before !== '' ? $before : null]),
        ];
    }

    public function parseDetail(ItemReference $reference, array $responses, string $before = ''): ItemDetail
    {
        $response = $responses['detail'] ?? null;
        $payload = Responses::graphql($response);
        $repository = Payload::array($payload, 'data', 'repository');
        $target = Payload::array($repository, 'target');
        if (Payload::string($target, 'title') === '') {
            throw new \UnexpectedValueException('Item not found or not visible to the token', 1764240301);
        }
        $isPullRequest = $reference->type === 'pull_request';
        $state = match (true) {
            Payload::bool($target, 'merged') => ItemDetail::STATE_MERGED,
            Payload::string($target, 'state') === 'CLOSED' => ItemDetail::STATE_CLOSED,
            default => ItemDetail::STATE_OPEN,
        };
        $viewer = Payload::string($payload, 'data', 'viewer', 'login');
        $timeline = Payload::array($target, $isPullRequest ? 'pullTimeline' : 'issueTimeline');

        $detail = new ItemDetail(
            $reference,
            Payload::string($target, 'title'),
            Payload::url($target, 'url'),
            $state,
            Payload::string($target, 'author', 'login'),
            $this->date(Payload::string($target, 'createdAt')),
            Payload::string($target, 'body'),
            Payload::string($target, 'bodyHTML'),
            $this->labels(Payload::list($target, 'labels', 'nodes')),
            array_map(static fn(array $user): array => [
                'id' => Payload::string($user, 'login'),
                'name' => Payload::string($user, 'login'),
            ], Payload::list($target, 'assignees', 'nodes')),
            $this->timeline(Payload::list($timeline, 'nodes'), Payload::list($target, 'reviewThreads', 'nodes')),
            $this->labels(Payload::list($repository, 'labels', 'nodes')),
            ['id' => $viewer, 'name' => $viewer],
            [],
            Payload::bool($target, 'isDraft'),
            Payload::string($target, 'headRefName'),
            Payload::string($target, 'baseRefName'),
            $isPullRequest ? strtolower(Payload::string($target, 'mergeable')) : '',
            Payload::string($target, 'viewerLatestReview', 'state') === 'APPROVED',
            page: $before,
            olderCursor: Payload::bool($timeline, 'pageInfo', 'hasPreviousPage') ? Payload::string($timeline, 'pageInfo', 'startCursor') : '',
        );
        $detail = $this->withActions($detail, $target, Payload::string($repository, 'viewerPermission'));

        // Fine-grained tokens and apps send no X-OAuth-Scopes: their permissions show in the API's answers.
        $scopes = $response?->getHeaderLine('X-OAuth-Scopes') ?? '';
        if ($scopes !== '' && array_intersect(['repo', 'public_repo'], array_map(trim(...), explode(',', $scopes))) === []) {
            return $detail->readOnly(self::WRITE_SCOPE);
        }
        return $detail;
    }

    public function actionRequest(ItemDetail $detail, string $action, array $parameters, Connection $connection): RequestInterface
    {
        [$owner, $name] = $this->repository($detail->reference);
        $issue = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/issues/' . $detail->reference->number;
        $repository = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);
        $pull = $repository . '/pulls/' . $detail->reference->number;
        $comment = $detail->comment($parameters['comment'] ?? '');

        return match ($action) {
            ItemAction::COMMENT => $this->rest($connection, 'POST', $issue . '/comments', ['body' => $parameters['body']]),
            ItemAction::EDIT => $this->rest($connection, 'PATCH', $issue, ['title' => $parameters['title'], 'body' => $parameters['description']]),
            ItemAction::CLOSE => $this->rest($connection, 'PATCH', $issue, ['state' => 'closed']),
            ItemAction::REOPEN => $this->rest($connection, 'PATCH', $issue, ['state' => 'open']),
            ItemAction::ASSIGN_ME => $this->rest($connection, 'POST', $issue . '/assignees', ['assignees' => [$detail->viewer['id']]]),
            ItemAction::UNASSIGN_ME => $this->rest($connection, 'DELETE', $issue . '/assignees', ['assignees' => [$detail->viewer['id']]]),
            ItemAction::ADD_LABEL => $this->rest($connection, 'POST', $issue . '/labels', ['labels' => [$parameters['label']]]),
            ItemAction::REMOVE_LABEL => $this->rest($connection, 'DELETE', $issue . '/labels/' . rawurlencode($parameters['label'])),
            ItemAction::APPROVE => $this->rest($connection, 'POST', $pull . '/reviews', ['event' => 'APPROVE', 'body' => $parameters['body'] ?? '']),
            ItemAction::REQUEST_CHANGES => $this->rest($connection, 'POST', $pull . '/reviews', ['event' => 'REQUEST_CHANGES', 'body' => $parameters['body']]),
            ItemAction::MERGE => $this->rest($connection, 'PUT', $pull . '/merge', ['merge_method' => 'merge']),
            ItemAction::MARK_DONE => $this->rest($connection, 'PATCH', '/notifications/threads/' . $detail->reference->todoId),
            ItemAction::EDIT_COMMENT => match ($comment['kind'] ?? '') {
                'issue' => $this->rest($connection, 'PATCH', $repository . '/issues/comments/' . $comment['number'], ['body' => $parameters['body']]),
                'review' => $this->rest($connection, 'PATCH', $repository . '/pulls/comments/' . $comment['number'], ['body' => $parameters['body']]),
                'pullReview' => $this->rest($connection, 'PUT', $pull . '/reviews/' . $comment['number'], ['body' => $parameters['body']]),
                default => throw new \InvalidArgumentException('Unknown comment', 1764240304),
            },
            ItemAction::DELETE_COMMENT => match ($comment['kind'] ?? '') {
                'issue' => $this->rest($connection, 'DELETE', $repository . '/issues/comments/' . $comment['number']),
                'review' => $this->rest($connection, 'DELETE', $repository . '/pulls/comments/' . $comment['number']),
                default => throw new \InvalidArgumentException('Unknown comment', 1764240305),
            },
            ItemAction::REPLY => $this->rest($connection, 'POST', $pull . '/comments/' . (int)$parameters['replyTo'] . '/replies', ['body' => $parameters['body']]),
            ItemAction::RESOLVE => $this->graphql($connection, self::RESOLVE_MUTATION, ['id' => $parameters['thread']]),
            ItemAction::UNRESOLVE => $this->graphql($connection, self::UNRESOLVE_MUTATION, ['id' => $parameters['thread']]),
            default => throw new \InvalidArgumentException('Unsupported action ' . $action, 1764240302),
        };
    }

    public function previewRequest(ItemReference $reference, Connection $connection, string $markdown): RequestInterface
    {
        return $this->rest($connection, 'POST', '/markdown', ['text' => $markdown, 'mode' => 'gfm', 'context' => $reference->project]);
    }

    /**
     * GitHub answers with the HTML itself.
     */
    public function parsePreview(ResponseInterface $response): string
    {
        return (string)$response->getBody();
    }

    public function suggestionRequest(ItemReference $reference, Connection $connection, string $kind, string $query): ?RequestInterface
    {
        [$owner, $name] = $this->repository($reference);
        if ($kind === 'user') {
            return $this->graphql($connection, self::USERS_QUERY, ['owner' => $owner, 'name' => $name, 'search' => $query]);
        }
        if ($kind !== 'issue') {
            // "#" covers issues and pull requests on GitHub.
            return null;
        }
        $search = 'repo:' . $owner . '/' . $name . ($query !== '' ? ' ' . $query : '');
        return $this->rest($connection, 'GET', '/search/issues?per_page=10&sort=updated&q=' . rawurlencode($search));
    }

    public function parseSuggestions(string $kind, ResponseInterface $response): array
    {
        $payload = Responses::json($response) ?? [];
        if ($kind === 'user') {
            return array_map(static fn(array $user): array => [
                'value' => Payload::string($user, 'login'),
                'label' => trim(Payload::string($user, 'login') . ' ' . Payload::string($user, 'name')),
            ], Payload::list($payload, 'data', 'repository', 'mentionableUsers', 'nodes'));
        }
        return array_map(static fn(array $entry): array => [
            'value' => Payload::string($entry, 'number'),
            'label' => Payload::string($entry, 'number') . ' ' . Payload::string($entry, 'title'),
        ], Payload::list($payload, 'items'));
    }

    /**
     * @param array<mixed> $target
     */
    private function withActions(ItemDetail $detail, array $target, string $permission): ItemDetail
    {
        $isPullRequest = $detail->reference->type === 'pull_request';
        $open = $detail->state === ItemDetail::STATE_OPEN;
        $actions = [];
        $allow = static function (bool $condition, string ...$names) use (&$actions): void {
            if ($condition) {
                array_push($actions, ...$names);
            }
        };
        // Anybody who can read the repository can comment (unless it is locked, which the API then reports).
        $allow($permission !== '', ItemAction::COMMENT);
        $allow(Payload::bool($target, 'viewerCanUpdate'), ItemAction::EDIT);
        $allow($open && Payload::bool($target, 'viewerCanClose'), ItemAction::CLOSE);
        $allow($detail->state === ItemDetail::STATE_CLOSED && Payload::bool($target, 'viewerCanReopen'), ItemAction::REOPEN);
        $canAssign = Payload::bool($target, 'viewerCanAssign');
        $allow($canAssign && !$detail->isAssignedToViewer(), ItemAction::ASSIGN_ME);
        $allow($canAssign && $detail->isAssignedToViewer(), ItemAction::UNASSIGN_ME);
        $allow(Payload::bool($target, 'viewerCanLabel'), ItemAction::ADD_LABEL, ItemAction::REMOVE_LABEL);
        if ($isPullRequest && $open) {
            $ownPullRequest = Payload::bool($target, 'viewerDidAuthor');
            $allow(!$ownPullRequest && !$detail->approvedByViewer, ItemAction::APPROVE);
            $allow(!$ownPullRequest, ItemAction::REQUEST_CHANGES);
            $allow(
                $detail->mergeStatus === 'mergeable' && !$detail->draft && in_array($permission, self::WRITE_PERMISSIONS, true),
                ItemAction::MERGE
            );
        }
        $allow($detail->reference->todoId !== '', ItemAction::MARK_DONE);
        $allow($detail->anyTimeline('canEdit'), ItemAction::EDIT_COMMENT);
        $allow($detail->anyTimeline('canDelete'), ItemAction::DELETE_COMMENT);
        $allow($detail->anyTimeline('canReply'), ItemAction::REPLY);
        $allow($detail->anyTimeline('canResolve'), ItemAction::RESOLVE, ItemAction::UNRESOLVE);

        return $detail->withActions($actions);
    }

    /**
     * @return array{string, string} owner and repository name
     */
    private function repository(ItemReference $reference): array
    {
        $parts = explode('/', $reference->project);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('GitHub repositories are "owner/name"', 1764240303);
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * @param list<array<mixed>> $nodes
     * @return list<array{name: string, color: string}>
     */
    private function labels(array $nodes): array
    {
        return array_values(array_filter(array_map(static fn(array $label): array => [
            'name' => Payload::string($label, 'name'),
            'color' => Responses::color(Payload::string($label, 'color')),
        ], $nodes), static fn(array $label): bool => $label['name'] !== ''));
    }

    /**
     * Timeline items → events and threads. Issue comments are threads of one (GitHub has no replies
     * there), reviews without text are events. The timeline does not contain review threads (although the
     * schema lists them): they follow the review their first comment belongs to, so they page with it.
     *
     * @param list<array<mixed>> $nodes
     * @param list<array<mixed>> $reviewThreads
     * @return list<array<string, mixed>>
     */
    private function timeline(array $nodes, array $reviewThreads): array
    {
        $threadsByReview = [];
        foreach ($reviewThreads as $thread) {
            $threadsByReview[Payload::string($thread, 'comments', 'nodes', '0', 'pullRequestReview', 'id')][] = $thread;
        }
        $timeline = [];
        foreach ($nodes as $node) {
            $type = Payload::string($node, '__typename');
            $actor = Payload::string($node, 'actor', 'login');
            $createdAt = $this->date(Payload::string($node, 'createdAt'));
            $entry = match ($type) {
                'IssueComment' => Timeline::thread('issue-' . Payload::string($node, 'databaseId'), [$this->comment('issue', $node)]),
                'PullRequestReview' => $this->review($node),
                'PullRequestCommit' => Timeline::event(
                    Payload::string($node, 'id'),
                    Payload::string($node, 'commit', 'author', 'user', 'login') ?: Payload::string($node, 'commit', 'author', 'name'),
                    $this->date(Payload::string($node, 'commit', 'committedDate')),
                    'committed',
                    trim(Payload::string($node, 'commit', 'abbreviatedOid') . ' ' . Payload::string($node, 'commit', 'messageHeadline')),
                ),
                'ClosedEvent' => Timeline::event(
                    Payload::string($node, 'id'),
                    $actor,
                    $createdAt,
                    Payload::string($node, 'stateReason') === 'NOT_PLANNED' ? 'closedNotPlanned' : 'closed'
                ),
                default => isset(self::EVENTS[$type])
                    ? Timeline::event(Payload::string($node, 'id'), $actor, $createdAt, self::EVENTS[$type], $this->eventSubject($node))
                    : null,
            };
            if ($entry !== null) {
                $timeline[] = $entry;
            }
            if ($type === 'PullRequestReview') {
                foreach ($threadsByReview[Payload::string($node, 'id')] ?? [] as $thread) {
                    $timeline[] = $this->reviewThread($thread);
                }
            }
        }
        return array_values(array_filter($timeline));
    }

    /**
     * @param array<mixed> $node
     */
    private function eventSubject(array $node): string
    {
        $source = Payload::array($node, 'source');
        if ($source !== []) {
            return Payload::string($source, 'repository', 'nameWithOwner') . '#' . Payload::string($source, 'number') . ' ' . Payload::string($source, 'title');
        }
        return Payload::string($node, 'label', 'name')
            ?: Payload::string($node, 'assignee', 'login')
            ?: Payload::string($node, 'requestedReviewer', 'login')
            ?: Payload::string($node, 'milestoneTitle')
            ?: Payload::string($node, 'currentTitle');
    }

    /**
     * @param array<mixed> $node
     * @return array<string, mixed>|null
     */
    private function reviewThread(array $node): ?array
    {
        $comments = array_map(fn(array $comment): array => $this->comment('review', $comment), Payload::list($node, 'comments', 'nodes'));
        $id = Payload::string($node, 'id');
        if ($comments === [] || $id === '') {
            return null;
        }
        $resolved = Payload::bool($node, 'isResolved');
        return Timeline::thread($id, $comments, [
            'resolvable' => true,
            'resolved' => $resolved,
            'canReply' => Payload::bool($node, 'viewerCanReply'),
            'canResolve' => Payload::bool($node, $resolved ? 'viewerCanUnresolve' : 'viewerCanResolve'),
            'replyTo' => (string)$comments[0]['number'],
            'path' => Payload::string($node, 'path'),
            'line' => Payload::int($node, 'line'),
            'url' => Payload::url(Payload::list($node, 'comments', 'nodes')[0], 'url'),
        ]);
    }

    /**
     * @param array<mixed> $node
     * @return array<string, mixed>|null
     */
    private function review(array $node): ?array
    {
        $state = Payload::string($node, 'state');
        if (trim(Payload::string($node, 'body')) !== '') {
            $comment = $this->comment('pullReview', $node);
            return Timeline::thread($comment['id'], [$comment], ['review' => strtolower($state)]);
        }
        // Empty "COMMENTED" reviews only wrap review comments, which their threads show.
        $event = ['APPROVED' => 'approved', 'CHANGES_REQUESTED' => 'changesRequested', 'DISMISSED' => 'reviewDismissed'][$state] ?? null;
        return $event !== null
            ? Timeline::event(Payload::string($node, 'id'), Payload::string($node, 'author', 'login'), $this->date(Payload::string($node, 'createdAt')), $event)
            : null;
    }

    /**
     * @param array<mixed> $node
     * @return array<string, mixed>
     */
    private function comment(string $kind, array $node): array
    {
        return Timeline::comment(
            $kind,
            Payload::int($node, 'databaseId'),
            Payload::string($node, 'author', 'login'),
            $this->date(Payload::string($node, 'createdAt')),
            Payload::string($node, 'body'),
            Payload::string($node, 'bodyHTML'),
            Payload::bool($node, 'viewerCanUpdate'),
            $kind !== 'pullReview' && Payload::bool($node, 'viewerCanDelete'),
        );
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        try {
            return $value !== '' ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(Connection $connection, bool $json): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $connection->token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        return $headers;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function graphql(Connection $connection, string $query, array $variables): RequestInterface
    {
        return new Request('POST', $connection->graphqlUrl(), $this->headers($connection, true), json_encode([
            'query' => $query,
            'variables' => $variables,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function rest(Connection $connection, string $method, string $path, ?array $body = null): RequestInterface
    {
        return new Request(
            $method,
            $connection->apiBaseUrl() . $path,
            $this->headers($connection, $body !== null),
            $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null
        );
    }
}
