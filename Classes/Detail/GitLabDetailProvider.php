<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Provider\Payload;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

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
        milestone { id title }
        participants(first: 30) { nodes { username } }
        subscribed discussionLocked
        discussions(last: 30, before: $before) {
          pageInfo { hasPreviousPage startCursor }
          nodes {
            id resolvable resolved userPermissions { resolveNote }
            notes { nodes {
              id body bodyHtml createdAt system internal url author { username }
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
            milestones(state: active, includeAncestors: true, first: 50) { nodes { id title } }
            target: issue(iid: $iid) {
              %s
              dueDate confidential
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
            milestones(state: active, includeAncestors: true, first: 50) { nodes { id title } }
            removeSourceBranchAfterMerge
            target: mergeRequest(iid: $iid) {
              %s
              draft sourceBranch targetBranch detailedMergeStatus mergeable
              approvedBy(first: 50) { nodes { username } }
              reviewers(first: 20) { nodes { username } }
              awardEmoji { nodes { name emoji user { username } } }
              diffHeadSha shouldBeRebased rebaseInProgress squashOnMerge squashReadOnly
              shouldRemoveSourceBranch forceRemoveSourceBranch autoMergeEnabled availableAutoMergeStrategies
              approvalsRequired approvalsLeft
              headPipeline { status path detailedStatus { text } }
              mergeabilityChecks { identifier status }
              userPermissions {
                readMergeRequest updateMergeRequest createNote canMerge canApprove adminMergeRequest pushToSourceBranch removeSourceBranch
              }
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

    /** %1$s issue or mergeRequest, %2$s what is set ("SetLocked"), %3$s the type of $value, %4$s the input fields */
    private const SET_MUTATION = <<<'GRAPHQL'
        mutation($path: ID!, $iid: String!, $value: %3$s) {
          result: %1$s%2$s(input: {projectPath: $path, iid: $iid, %4$s}) { errors }
        }
        GRAPHQL;

    private const ACCEPT_MUTATION = <<<'GRAPHQL'
        mutation($path: ID!, $iid: String!, $sha: String!, $squash: Boolean, $remove: Boolean) {
          mergeRequestAccept(input: {
            projectPath: $path, iid: $iid, strategy: MERGE_WHEN_CHECKS_PASS, sha: $sha, squash: $squash, shouldRemoveSourceBranch: $remove
          }) { errors }
        }
        GRAPHQL;

    /** GitLab pipeline status → MergeState::PIPELINE_STATES */
    private const PIPELINE_STATES = [
        'SUCCESS' => 'success', 'FAILED' => 'failed', 'RUNNING' => 'running', 'CANCELED' => 'canceled', 'CANCELING' => 'canceled',
        'SKIPPED' => 'skipped', 'MANUAL' => 'manual', 'CREATED' => 'pending', 'PENDING' => 'pending', 'PREPARING' => 'pending',
        'WAITING_FOR_RESOURCE' => 'pending', 'WAITING_FOR_CALLBACK' => 'pending', 'SCHEDULED' => 'pending',
    ];

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
        $path = '/projects/' . rawurlencode($reference->project) . ($reference->isChange() ? '/merge_requests/' : '/issues/') . $reference->number;
        // Linked items through REST: stable on every GitLab version (GraphQL has them as experimental work item fields).
        if ($reference->isChange()) {
            $requests['closesIssues'] = $this->rest($connection, 'GET', $path . '/closes_issues?per_page=20');
        } else {
            // The GraphQL type Issue has no awardEmoji field.
            $requests['awards'] = $this->rest($connection, 'GET', $path . '/award_emoji?per_page=100');
            $requests['links'] = $this->rest($connection, 'GET', $path . '/links');
            $requests['relatedMergeRequests'] = $this->rest($connection, 'GET', $path . '/related_merge_requests?per_page=20');
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
            reviewers: array_map(static fn(array $user): array => [
                'name' => Payload::string($user, 'username'),
                'state' => '',
                'removable' => true,
            ], Payload::list($target, 'reviewers', 'nodes')),
            milestone: $this->milestone(Payload::array($target, 'milestone')),
            availableMilestones: array_values(array_filter(
                array_map($this->milestone(...), Payload::list($payload, 'data', 'project', 'milestones', 'nodes')),
                static fn(array $milestone): bool => $milestone['id'] !== ''
            )),
            dueDate: preg_match('/^\d{4}-\d{2}-\d{2}/', Payload::string($target, 'dueDate'), $date) === 1 ? $date[0] : '',
            confidential: Payload::bool($target, 'confidential'),
            locked: Payload::bool($target, 'discussionLocked'),
            participants: array_values(array_filter(array_map(
                static fn(array $user): string => Payload::string($user, 'username'),
                Payload::list($target, 'participants', 'nodes')
            ))),
            subscribed: Payload::bool($target, 'subscribed'),
            merge: $reference->isChange() ? $this->mergeState($target, Payload::array($payload, 'data', 'project'), $permissions) : null,
            linked: $this->linked($responses),
        );
        $detail = $this->withActions($detail, $permissions, $target);

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
            ItemAction::INTERNAL_NOTE => $this->rest($connection, 'POST', $path . '/notes', ['body' => $parameters['body'], 'internal' => true]),
            ItemAction::EDIT => $this->rest($connection, 'PUT', $path, ['title' => $parameters['title'], 'description' => $parameters['description']]),
            ItemAction::CLOSE => $this->rest($connection, 'PUT', $path, ['state_event' => 'close']),
            ItemAction::REOPEN => $this->rest($connection, 'PUT', $path, ['state_event' => 'reopen']),
            ItemAction::ASSIGN_ME => $this->rest($connection, 'PUT', $path, ['assignee_ids' => array_values(array_unique([...$assigneeIds, $viewerId]))]),
            ItemAction::UNASSIGN_ME => $this->rest($connection, 'PUT', $path, ['assignee_ids' => array_values(array_diff($assigneeIds, [$viewerId]))]),
            ItemAction::ADD_LABEL => $this->rest($connection, 'PUT', $path, ['add_labels' => $parameters['label']]),
            ItemAction::REMOVE_LABEL => $this->rest($connection, 'PUT', $path, ['remove_labels' => $parameters['label']]),
            ItemAction::APPROVE => $this->rest($connection, 'POST', $path . '/approve'),
            ItemAction::UNAPPROVE => $this->rest($connection, 'POST', $path . '/unapprove'),
            ItemAction::MERGE => $this->rest($connection, 'PUT', $path . '/merge', $this->mergeOptions($detail, $parameters)),
            ItemAction::ENABLE_AUTO_MERGE => $this->graphql($connection, self::ACCEPT_MUTATION, [
                'path' => $reference->project,
                'iid' => (string)$reference->number,
                'sha' => $parameters['sha'],
                'squash' => $this->mergeOptions($detail, $parameters)['squash'] ?? null,
                'remove' => $this->mergeOptions($detail, $parameters)['should_remove_source_branch'] ?? null,
            ]),
            ItemAction::DISABLE_AUTO_MERGE => $this->rest($connection, 'POST', $path . '/cancel_merge_when_pipeline_succeeds'),
            ItemAction::UPDATE_BRANCH => $this->rest($connection, 'PUT', $path . '/rebase'),
            ItemAction::MARK_READY, ItemAction::MARK_DRAFT => $this->set($connection, $reference, 'SetDraft', 'Boolean!', 'draft: $value', $action === ItemAction::MARK_DRAFT),
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
            ItemAction::ADD_ASSIGNEE, ItemAction::REMOVE_ASSIGNEE => $this->set($connection, $reference, 'SetAssignees', '[String!]!', 'assigneeUsernames: $value, operationMode: ' . ($action === ItemAction::ADD_ASSIGNEE ? 'APPEND' : 'REMOVE'), [$parameters['user']]),
            ItemAction::ADD_REVIEWER, ItemAction::REMOVE_REVIEWER => $this->set($connection, $reference, 'SetReviewers', '[String!]!', 'reviewerUsernames: $value, operationMode: ' . ($action === ItemAction::ADD_REVIEWER ? 'APPEND' : 'REMOVE'), [$parameters['user']]),
            // No GraphQL mutation for issue milestones: REST for both, 0 removes it.
            ItemAction::SET_MILESTONE => $this->rest($connection, 'PUT', $path, ['milestone_id' => (int)$parameters['milestone']]),
            ItemAction::SET_DUE_DATE => $this->set($connection, $reference, 'SetDueDate', 'Time', 'dueDate: $value', $parameters['dueDate'] !== '' ? $parameters['dueDate'] : null),
            ItemAction::SET_CONFIDENTIAL => $this->set($connection, $reference, 'SetConfidential', 'Boolean!', 'confidential: $value', $parameters['confidential'] === '1'),
            ItemAction::LOCK, ItemAction::UNLOCK => $this->set($connection, $reference, 'SetLocked', 'Boolean!', 'locked: $value', $action === ItemAction::LOCK),
            ItemAction::SUBSCRIBE, ItemAction::UNSUBSCRIBE => $this->set($connection, $reference, 'SetSubscription', 'Boolean!', 'subscribedState: $value', $action === ItemAction::SUBSCRIBE),
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

    /**
     * POST /projects/:id/uploads answers {"markdown": "![name](/uploads/…/name)"}, relative to the project.
     */
    public function uploadRequest(ItemReference $reference, Connection $connection, string $filename, StreamInterface $file): RequestInterface
    {
        $body = new MultipartStream([['name' => 'file', 'contents' => $file, 'filename' => $filename]]);
        return new Request('POST', $connection->apiBaseUrl() . '/projects/' . rawurlencode($reference->project) . '/uploads', [
            'Accept' => 'application/json',
            'PRIVATE-TOKEN' => $connection->token,
            'Content-Type' => 'multipart/form-data; boundary=' . $body->getBoundary(),
        ], $body);
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
    private function withActions(ItemDetail $detail, array $permissions, array $target): ItemDetail
    {
        $mergeable = Payload::bool($target, 'mergeable');
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
        // Internal notes need the Reporter role, like labels and assignees.
        $allow(Payload::bool($permissions, 'createNote') && $admin, ItemAction::INTERNAL_NOTE);
        $allow($update, ItemAction::EDIT);
        $allow($open && $update, ItemAction::CLOSE);
        $allow($detail->state === ItemDetail::STATE_CLOSED && ($isChange ? $update : Payload::bool($permissions, 'reopenIssue')), ItemAction::REOPEN);
        $allow($admin && !$detail->isAssignedToViewer(), ItemAction::ASSIGN_ME);
        $allow($admin && $detail->isAssignedToViewer(), ItemAction::UNASSIGN_ME);
        $allow($admin, ItemAction::ADD_LABEL, ItemAction::REMOVE_LABEL, ItemAction::ADD_ASSIGNEE, ItemAction::REMOVE_ASSIGNEE, ItemAction::SET_MILESTONE);
        $allow($admin && !$detail->locked, ItemAction::LOCK);
        $allow($admin && $detail->locked, ItemAction::UNLOCK);
        $allow($admin && !$isChange, ItemAction::SET_DUE_DATE, ItemAction::SET_CONFIDENTIAL);
        $allow($admin && $isChange && $open, ItemAction::ADD_REVIEWER, ItemAction::REMOVE_REVIEWER);
        $read = Payload::bool($permissions, $isChange ? 'readMergeRequest' : 'readIssue');
        $allow($read && !$detail->subscribed, ItemAction::SUBSCRIBE);
        $allow($read && $detail->subscribed, ItemAction::UNSUBSCRIBE);
        if ($isChange && $open) {
            $allow(!$detail->approvedByViewer && Payload::bool($permissions, 'canApprove'), ItemAction::APPROVE);
            $allow($detail->approvedByViewer, ItemAction::UNAPPROVE);
            $canMerge = Payload::bool($permissions, 'canMerge');
            $autoMerge = $detail->merge->autoMerge ?? false;
            $allow($mergeable && !$detail->draft && $canMerge, ItemAction::MERGE);
            $allow(
                !$mergeable && !$autoMerge && $canMerge && in_array('MERGE_WHEN_CHECKS_PASS', Payload::array($target, 'availableAutoMergeStrategies'), true),
                ItemAction::ENABLE_AUTO_MERGE
            );
            $allow($autoMerge && $canMerge, ItemAction::DISABLE_AUTO_MERGE);
            $allow(
                Payload::bool($target, 'shouldBeRebased') && !Payload::bool($target, 'rebaseInProgress') && Payload::bool($permissions, 'pushToSourceBranch'),
                ItemAction::UPDATE_BRANCH
            );
            $allow($update && $detail->draft, ItemAction::MARK_READY);
            $allow($update && !$detail->draft, ItemAction::MARK_DRAFT);
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
                Payload::bool($note, 'internal'),
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
     * Linked items from the REST answers of detailRequests(); a failed request just shows none.
     *
     * @param array<string, ResponseInterface|null> $responses
     * @return list<array{relation: string, type: string, project: string, number: int, title: string, state: string, url: string}>
     */
    private function linked(array $responses): array
    {
        $linked = [];
        $sources = ['closesIssues' => 'issue', 'links' => 'issue', 'relatedMergeRequests' => 'merge_request'];
        foreach ($sources as $key => $type) {
            foreach (Payload::list(Responses::json($responses[$key] ?? null) ?? []) as $item) {
                $relation = match ($key) {
                    'closesIssues' => 'closes',
                    'relatedMergeRequests' => 'mergeRequest',
                    default => ['blocks' => 'blocks', 'is_blocked_by' => 'isBlockedBy'][Payload::string($item, 'link_type')] ?? 'relatesTo',
                };
                // "group/project#12", "group/project!3"
                if (preg_match('/^(.+)[#!](\d+)$/', Payload::string($item, 'references', 'full'), $match) !== 1) {
                    continue;
                }
                $linked[] = [
                    'relation' => $relation,
                    'type' => $type,
                    'project' => $match[1],
                    'number' => (int)$match[2],
                    'title' => Payload::string($item, 'title'),
                    'state' => ['opened' => 'open', 'locked' => 'open', 'merged' => 'merged'][Payload::string($item, 'state')] ?? 'closed',
                    'url' => Payload::url($item, 'web_url'),
                ];
            }
        }
        return $linked;
    }

    /**
     * @param array<mixed> $target the merge request
     * @param array<mixed> $project
     * @param array<mixed> $permissions
     */
    private function mergeState(array $target, array $project, array $permissions): MergeState
    {
        $blockers = [];
        foreach (Payload::list($target, 'mergeabilityChecks') as $check) {
            if (Payload::string($check, 'status') === 'FAILED') {
                $blockers[] = strtolower(Payload::string($check, 'identifier'));
            }
        }
        $status = Payload::string($target, 'detailedMergeStatus');
        if ($blockers === [] && !in_array($status, ['', 'MERGEABLE', 'mergeable'], true)) {
            $blockers[] = strtolower($status);
        }
        $url = parse_url(Payload::url($target, 'webUrl'));
        $pipelinePath = Payload::string($target, 'headPipeline', 'path');
        // null: the merge request does not say, the project's default applies.
        $removeBranch = $target['shouldRemoveSourceBranch'] ?? null;
        return new MergeState(
            pipeline: self::PIPELINE_STATES[Payload::string($target, 'headPipeline', 'status')] ?? '',
            pipelineText: Payload::string($target, 'headPipeline', 'detailedStatus', 'text'),
            pipelineUrl: str_starts_with($pipelinePath, '/') && isset($url['host']) ? 'https://' . $url['host'] . $pipelinePath : '',
            approvalsRequired: Payload::int($target, 'approvalsRequired'),
            approvalsLeft: Payload::int($target, 'approvalsLeft'),
            blockers: array_values(array_unique(array_filter($blockers, static fn(string $blocker): bool => preg_match('/^[a-z_]{1,64}$/', $blocker) === 1))),
            squash: Payload::bool($target, 'squashOnMerge'),
            squashLocked: Payload::bool($target, 'squashReadOnly'),
            deleteBranch: Payload::bool($target, 'forceRemoveSourceBranch')
                || (is_bool($removeBranch) ? $removeBranch : Payload::bool($project, 'removeSourceBranchAfterMerge')),
            canDeleteBranch: Payload::bool($permissions, 'removeSourceBranch'),
            autoMerge: Payload::bool($target, 'autoMergeEnabled'),
            headSha: preg_match('/^[0-9a-f]{40,64}$/', Payload::string($target, 'diffHeadSha')) === 1 ? Payload::string($target, 'diffHeadSha') : '',
        );
    }

    /**
     * Options of PUT …/merge; squash only if the project lets the user choose.
     *
     * @param array<string, string> $parameters
     * @return array<string, mixed>
     */
    private function mergeOptions(ItemDetail $detail, array $parameters): array
    {
        $options = ['sha' => $parameters['sha']];
        if ($detail->merge->canDeleteBranch ?? false) {
            $options['should_remove_source_branch'] = $parameters['deleteBranch'] === '1';
        }
        if (!($detail->merge->squashLocked ?? true)) {
            $options['squash'] = $parameters['squash'] === '1';
        }
        return array_filter($options, static fn(mixed $value): bool => $value !== '');
    }

    /**
     * @param array<mixed> $node
     * @return array{id: string, title: string} id: the numeric id REST takes, "" for none
     */
    private function milestone(array $node): array
    {
        $id = (string)substr((string)strrchr(Payload::string($node, 'id'), '/'), 1);
        return ctype_digit($id) ? ['id' => $id, 'title' => Payload::string($node, 'title')] : ['id' => '', 'title' => ''];
    }

    /**
     * One of the *Set* mutations (issueSetLocked, mergeRequestSetReviewers, …) of the item.
     */
    private function set(Connection $connection, ItemReference $reference, string $what, string $type, string $input, mixed $value): RequestInterface
    {
        $query = sprintf(self::SET_MUTATION, $reference->isChange() ? 'mergeRequest' : 'issue', $what, $type, $input);
        return $this->graphql($connection, $query, ['path' => $reference->project, 'iid' => (string)$reference->number, 'value' => $value]);
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
