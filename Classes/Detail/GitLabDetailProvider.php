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
 * Details through one GraphQL query (rendered HTML and the user's permissions included),
 * actions through the REST API. Write actions need a token with the "api" scope.
 */
final readonly class GitLabDetailProvider implements DetailProviderInterface
{
    public const WRITE_SCOPE = 'api';

    private const COMMON_FIELDS = <<<'GRAPHQL'
        id title state webUrl description descriptionHtml createdAt
        author { username }
        labels(first: 50) { nodes { title color } }
        assignees(first: 20) { nodes { id username } }
        discussions(last: 30, before: $before) {
          pageInfo { hasPreviousPage startCursor }
          nodes {
            id resolvable resolved userPermissions { resolveNote }
            notes { nodes {
              id body bodyHtml createdAt system url author { username }
              position { filePath newLine oldLine }
              awardEmoji { nodes { name emoji user { username } } }
              userPermissions { adminNote awardEmoji }
            } }
          }
        }
        GRAPHQL;

    private const ISSUE_QUERY = <<<'GRAPHQL'
        query($fullPath: ID!, $iid: String!, $before: String) {
          currentUser { id username }
          project(fullPath: $fullPath) {
            labels(first: 100, includeAncestorGroups: true) { nodes { title color } }
            target: issue(iid: $iid) {
              %s
              userPermissions { readIssue updateIssue createNote reopenIssue adminIssue }
            }
          }
        }
        GRAPHQL;

    private const MERGE_REQUEST_QUERY = <<<'GRAPHQL'
        query($fullPath: ID!, $iid: String!, $before: String) {
          currentUser { id username }
          project(fullPath: $fullPath) {
            labels(first: 100, includeAncestorGroups: true) { nodes { title color } }
            target: mergeRequest(iid: $iid) {
              %s
              draft sourceBranch targetBranch detailedMergeStatus mergeable
              approvedBy(first: 50) { nodes { username } }
              awardEmoji { nodes { name emoji user { username } } }
              userPermissions { readMergeRequest updateMergeRequest createNote canMerge canApprove adminMergeRequest }
            }
          }
        }
        GRAPHQL;

    private const RESOLVE_MUTATION = <<<'GRAPHQL'
        mutation($id: DiscussionID!, $resolve: Boolean!) {
          discussionToggleResolve(input: {id: $id, resolve: $resolve}) { errors }
        }
        GRAPHQL;

    private const AWARD_MUTATION = <<<'GRAPHQL'
        mutation($id: AwardableID!, $name: String!) {
          awardEmojiToggle(input: {awardableId: $id, name: $name}) { errors }
        }
        GRAPHQL;

    private const USERS_QUERY = <<<'GRAPHQL'
        query($fullPath: ID!, $search: String) {
          project(fullPath: $fullPath) { autocompleteUsers(search: $search) { username name } }
        }
        GRAPHQL;

    public function detailRequests(ItemReference $reference, Connection $connection, string $before = ''): array
    {
        $query = sprintf($reference->isChange() ? self::MERGE_REQUEST_QUERY : self::ISSUE_QUERY, self::COMMON_FIELDS);
        $requests = [
            'detail' => new Request('POST', $connection->graphqlUrl(), [
                'Authorization' => 'Bearer ' . $connection->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], json_encode([
                'query' => $query,
                'variables' => ['fullPath' => $reference->project, 'iid' => (string)$reference->number, 'before' => $before !== '' ? $before : null],
            ], JSON_THROW_ON_ERROR)),
            // Which scopes the token has (GitLab 15.5+); without "api" the sheet is read-only.
            'scopes' => $this->rest($connection, 'GET', '/personal_access_tokens/self'),
        ];
        if (!$reference->isChange()) {
            // The GraphQL type Issue has no awardEmoji field.
            $requests['awards'] = $this->rest($connection, 'GET', '/projects/' . rawurlencode($reference->project) . '/issues/' . $reference->number . '/award_emoji?per_page=100');
        }
        return $requests;
    }

    public function parseDetail(ItemReference $reference, array $responses, string $before = ''): ItemDetail
    {
        $payload = Responses::graphql($responses['detail'] ?? null);
        $target = Payload::array($payload, 'data', 'project', 'target');
        if ($target === []) {
            throw new \UnexpectedValueException('Item not found or not visible to the token', 1764240101);
        }
        $viewer = [
            'id' => (string)(int)substr((string)strrchr(Payload::string($payload, 'data', 'currentUser', 'id'), '/'), 1),
            'name' => Payload::string($payload, 'data', 'currentUser', 'username'),
        ];
        $permissions = Payload::array($target, 'userPermissions');
        $approvedBy = array_map(static fn(array $user): string => Payload::string($user, 'username'), Payload::list($target, 'approvedBy', 'nodes'));
        $state = match (Payload::string($target, 'state')) {
            'merged' => ItemDetail::STATE_MERGED,
            'closed' => ItemDetail::STATE_CLOSED,
            default => ItemDetail::STATE_OPEN,
        };

        $awards = $reference->isChange()
            ? Payload::list($target, 'awardEmoji', 'nodes')
            : Payload::list(Responses::json($responses['awards'] ?? null) ?? []);

        $detail = new ItemDetail(
            $reference,
            Payload::string($target, 'title'),
            Payload::url($target, 'webUrl'),
            $state,
            Payload::string($target, 'author', 'username'),
            $this->date(Payload::string($target, 'createdAt')),
            Payload::string($target, 'description'),
            Payload::string($target, 'descriptionHtml'),
            $this->labels(Payload::list($target, 'labels', 'nodes')),
            array_map(fn(array $user): array => [
                'id' => (string)(int)substr((string)strrchr(Payload::string($user, 'id'), '/'), 1),
                'name' => Payload::string($user, 'username'),
            ], Payload::list($target, 'assignees', 'nodes')),
            $this->timeline(Payload::list($target, 'discussions', 'nodes'), Payload::bool($permissions, 'createNote'), $viewer['name']),
            $this->labels(Payload::list($payload, 'data', 'project', 'labels', 'nodes')),
            $viewer,
            [],
            Payload::bool($target, 'draft'),
            Payload::string($target, 'sourceBranch'),
            Payload::string($target, 'targetBranch'),
            Payload::string($target, 'detailedMergeStatus'),
            in_array($viewer['name'], $approvedBy, true),
            page: $before,
            olderCursor: Payload::bool($target, 'discussions', 'pageInfo', 'hasPreviousPage')
                ? Payload::string($target, 'discussions', 'pageInfo', 'startCursor')
                : '',
            reactions: $this->reactions($awards, $viewer['name']),
            subject: $this->awardable(Payload::string($target, 'id')),
            canReact: Payload::bool($permissions, $reference->isChange() ? 'readMergeRequest' : 'readIssue'),
        );
        $detail = $this->withActions($detail, $permissions, Payload::bool($target, 'mergeable'));

        $scopes = Responses::json($responses['scopes'] ?? null);
        if ($scopes !== null && !in_array(self::WRITE_SCOPE, Payload::array($scopes, 'scopes'), true)) {
            return $detail->readOnly(self::WRITE_SCOPE);
        }
        return $detail;
    }

    public function actionRequest(ItemDetail $detail, string $action, array $parameters, Connection $connection): RequestInterface
    {
        $reference = $detail->reference;
        $path = '/projects/' . rawurlencode($reference->project) . '/' . ($reference->isChange() ? 'merge_requests' : 'issues') . '/' . $reference->number;
        $assigneeIds = array_map(intval(...), array_column($detail->assignees, 'id'));
        $viewerId = (int)$detail->viewer['id'];

        return match ($action) {
            ItemAction::COMMENT => $this->rest($connection, 'POST', $path . '/notes', ['body' => $parameters['body']]),
            ItemAction::EDIT => $this->rest($connection, 'PUT', $path, ['title' => $parameters['title'], 'description' => $parameters['description']]),
            ItemAction::CLOSE => $this->rest($connection, 'PUT', $path, ['state_event' => 'close']),
            ItemAction::REOPEN => $this->rest($connection, 'PUT', $path, ['state_event' => 'reopen']),
            ItemAction::ASSIGN_ME => $this->rest($connection, 'PUT', $path, ['assignee_ids' => array_values(array_unique([...$assigneeIds, $viewerId]))]),
            ItemAction::UNASSIGN_ME => $this->rest($connection, 'PUT', $path, ['assignee_ids' => array_values(array_diff($assigneeIds, [$viewerId]))]),
            ItemAction::ADD_LABEL => $this->rest($connection, 'PUT', $path, ['add_labels' => $parameters['label']]),
            ItemAction::REMOVE_LABEL => $this->rest($connection, 'PUT', $path, ['remove_labels' => $parameters['label']]),
            ItemAction::APPROVE => $this->rest($connection, 'POST', $path . '/approve'),
            ItemAction::UNAPPROVE => $this->rest($connection, 'POST', $path . '/unapprove'),
            ItemAction::MERGE => $this->rest($connection, 'PUT', $path . '/merge'),
            ItemAction::MARK_DONE => $this->rest($connection, 'POST', '/todos/' . $reference->todoId . '/mark_as_done'),
            ItemAction::EDIT_COMMENT => $this->rest($connection, 'PUT', $path . '/notes/' . (int)$parameters['comment'], ['body' => $parameters['body']]),
            ItemAction::DELETE_COMMENT => $this->rest($connection, 'DELETE', $path . '/notes/' . (int)$parameters['comment']),
            ItemAction::REPLY => $this->rest($connection, 'POST', $path . '/discussions/' . $parameters['thread'] . '/notes', ['body' => $parameters['body']]),
            ItemAction::RESOLVE, ItemAction::UNRESOLVE => $this->graphql($connection, self::RESOLVE_MUTATION, [
                'id' => 'gid://gitlab/Discussion/' . $parameters['thread'],
                'resolve' => $action === ItemAction::RESOLVE,
            ]),
            // Toggles: perform() has checked the current state already.
            ItemAction::REACT => $this->graphql($connection, self::AWARD_MUTATION, ['id' => $parameters['subject'], 'name' => $parameters['emoji']]),
            default => throw new \InvalidArgumentException('Unsupported action ' . $action, 1764240102),
        };
    }

    public function previewRequest(ItemReference $reference, Connection $connection, string $markdown): RequestInterface
    {
        return $this->rest($connection, 'POST', '/markdown', ['text' => $markdown, 'gfm' => true, 'project' => $reference->project]);
    }

    public function parsePreview(ResponseInterface $response): string
    {
        return Payload::string(Responses::json($response) ?? [], 'html');
    }

    public function suggestionRequest(ItemReference $reference, Connection $connection, string $kind, string $query): ?RequestInterface
    {
        if ($kind === 'user') {
            return $this->graphql($connection, self::USERS_QUERY, ['fullPath' => $reference->project, 'search' => $query]);
        }
        $list = ['issue' => 'issues', 'mergeRequest' => 'merge_requests'][$kind] ?? null;
        if ($list === null) {
            return null;
        }
        // A number is an iid ("#12"), anything else searches titles and descriptions.
        $filter = ctype_digit($query) ? 'iids[]=' . $query : 'search=' . rawurlencode($query);
        return $this->rest($connection, 'GET', '/projects/' . rawurlencode($reference->project) . '/' . $list . '?per_page=10&order_by=updated_at&' . $filter);
    }

    public function parseSuggestions(string $kind, ResponseInterface $response): array
    {
        $payload = Responses::json($response) ?? [];
        if ($kind === 'user') {
            return array_slice(array_map(static fn(array $user): array => [
                'value' => Payload::string($user, 'username'),
                'label' => trim(Payload::string($user, 'username') . ' ' . Payload::string($user, 'name')),
            ], Payload::list($payload, 'data', 'project', 'autocompleteUsers')), 0, 10);
        }
        return array_map(static fn(array $entry): array => [
            'value' => Payload::string($entry, 'iid'),
            'label' => Payload::string($entry, 'iid') . ' ' . Payload::string($entry, 'title'),
        ], Payload::list($payload));
    }

    /**
     * @param array<mixed> $permissions
     */
    private function withActions(ItemDetail $detail, array $permissions, bool $mergeable): ItemDetail
    {
        $isChange = $detail->reference->isChange();
        $open = $detail->state === ItemDetail::STATE_OPEN;
        $update = Payload::bool($permissions, $isChange ? 'updateMergeRequest' : 'updateIssue');
        // Labels and assignees are project metadata: Reporter and up.
        $admin = Payload::bool($permissions, $isChange ? 'adminMergeRequest' : 'adminIssue');

        $actions = [];
        $allow = static function (bool $condition, string ...$names) use (&$actions): void {
            if ($condition) {
                array_push($actions, ...$names);
            }
        };
        $allow(Payload::bool($permissions, 'createNote'), ItemAction::COMMENT);
        $allow($update, ItemAction::EDIT);
        $allow($open && $update, ItemAction::CLOSE);
        $allow($detail->state === ItemDetail::STATE_CLOSED && ($isChange ? $update : Payload::bool($permissions, 'reopenIssue')), ItemAction::REOPEN);
        $allow($admin && !$detail->isAssignedToViewer(), ItemAction::ASSIGN_ME);
        $allow($admin && $detail->isAssignedToViewer(), ItemAction::UNASSIGN_ME);
        $allow($admin, ItemAction::ADD_LABEL, ItemAction::REMOVE_LABEL);
        if ($isChange && $open) {
            $allow(!$detail->approvedByViewer && Payload::bool($permissions, 'canApprove'), ItemAction::APPROVE);
            $allow($detail->approvedByViewer, ItemAction::UNAPPROVE);
            $allow($mergeable && !$detail->draft && Payload::bool($permissions, 'canMerge'), ItemAction::MERGE);
        }
        $allow($detail->reference->todoId !== '', ItemAction::MARK_DONE);
        $allow($detail->anyTimeline('canEdit'), ItemAction::EDIT_COMMENT);
        $allow($detail->anyTimeline('canDelete'), ItemAction::DELETE_COMMENT);
        $allow($detail->anyTimeline('canReply'), ItemAction::REPLY);
        $allow($detail->anyTimeline('canResolve'), ItemAction::RESOLVE, ItemAction::UNRESOLVE);
        $allow($detail->canReact || $detail->anyTimeline('canReact'), ItemAction::REACT);
        $allow(in_array(ItemAction::EDIT, $actions, true) || $detail->anyTimeline('canEdit'), ItemAction::TOGGLE_TASK);

        return $detail->withActions($actions);
    }

    /**
     * @param list<array<mixed>> $nodes
     * @return list<array{name: string, color: string}>
     */
    private function labels(array $nodes): array
    {
        return array_values(array_filter(array_map(static fn(array $label): array => [
            'name' => Payload::string($label, 'title'),
            'color' => Responses::color(Payload::string($label, 'color')),
        ], $nodes), static fn(array $label): bool => $label['name'] !== ''));
    }

    /**
     * Discussions → timeline: system notes become events, the rest threads (a single comment is a
     * discussion of its own; replying turns it into a thread, like on GitLab).
     *
     * @param list<array<mixed>> $discussions
     * @return list<array<string, mixed>>
     */
    private function timeline(array $discussions, bool $canComment, string $viewer): array
    {
        $timeline = [];
        foreach ($discussions as $discussion) {
            $notes = Payload::list($discussion, 'notes', 'nodes');
            $id = (string)substr((string)strrchr(Payload::string($discussion, 'id'), '/'), 1);
            if ($notes === [] || preg_match('/^[0-9a-f]{1,64}$/', $id) !== 1) {
                continue;
            }
            $first = $notes[0];
            if (Payload::bool($first, 'system')) {
                $timeline[] = Timeline::event(
                    $id,
                    Payload::string($first, 'author', 'username'),
                    $this->date(Payload::string($first, 'createdAt')),
                    '',
                    html: Payload::string($first, 'bodyHtml'),
                );
                continue;
            }
            $resolvable = Payload::bool($discussion, 'resolvable');
            $timeline[] = Timeline::thread($id, array_map(fn(array $note): array => Timeline::comment(
                'note',
                (int)substr((string)strrchr(Payload::string($note, 'id'), '/'), 1),
                Payload::string($note, 'author', 'username'),
                $this->date(Payload::string($note, 'createdAt')),
                Payload::string($note, 'body'),
                Payload::string($note, 'bodyHtml'),
                Payload::bool($note, 'userPermissions', 'adminNote'),
                Payload::bool($note, 'userPermissions', 'adminNote'),
                $this->reactions(Payload::list($note, 'awardEmoji', 'nodes'), $viewer),
                $this->awardable(Payload::string($note, 'id')),
                Payload::bool($note, 'userPermissions', 'awardEmoji'),
            ), $notes), [
                'resolvable' => $resolvable,
                'resolved' => Payload::bool($discussion, 'resolved'),
                'canReply' => $canComment,
                'canResolve' => $resolvable && Payload::bool($discussion, 'userPermissions', 'resolveNote'),
                'replyTo' => $id,
                'path' => Payload::string($first, 'position', 'filePath'),
                'line' => Payload::int($first, 'position', 'newLine') ?: Payload::int($first, 'position', 'oldLine'),
                'url' => Payload::url($first, 'url'),
            ]);
        }
        return $timeline;
    }

    /**
     * Award emoji of GraphQL ("user": {"username"}) and REST (the same, no "emoji").
     *
     * @param list<array<mixed>> $awards
     * @return list<array{name: string, emoji: string, count: int, reacted: bool}>
     */
    private function reactions(array $awards, string $viewer): array
    {
        return Reactions::fromAwards(array_map(static fn(array $award): array => [
            'name' => Payload::string($award, 'name'),
            'emoji' => Payload::string($award, 'emoji'),
            'user' => Payload::string($award, 'user', 'username'),
        ], $awards), $viewer);
    }

    /**
     * A global id that can go into AwardableID ("gid://gitlab/Note/12"), "" for anything else.
     */
    private function awardable(string $id): string
    {
        return preg_match('#^gid://gitlab/[A-Za-z:]+/\d+$#', $id) === 1 ? $id : '';
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
     * @param array<string, mixed> $variables
     */
    private function graphql(Connection $connection, string $query, array $variables): RequestInterface
    {
        return new Request('POST', $connection->graphqlUrl(), [
            'Authorization' => 'Bearer ' . $connection->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function rest(Connection $connection, string $method, string $path, ?array $body = null): RequestInterface
    {
        $headers = ['Accept' => 'application/json', 'PRIVATE-TOKEN' => $connection->token];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return new Request($method, $connection->apiBaseUrl() . $path, $headers, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
    }
}
