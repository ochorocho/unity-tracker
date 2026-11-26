<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\RequestOptions;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Provider\Payload;
use Ochorocho\UnityTracker\Service\FetchError;
use Ochorocho\UnityTracker\Service\FetchFailedException;
use Psr\Log\LoggerInterface;

/**
 * Loads one item's details and performs actions on it. Every action is checked against the
 * permissions the API reports for the token's user before it is sent.
 */
final readonly class ItemDetailService
{
    /** Autocompletion in the Markdown editor: "@" users, "#" issues (GitHub: and pull requests), "!" merge requests */
    public const SUGGESTION_KINDS = ['user', 'issue', 'mergeRequest'];
    public const MAX_PREVIEW = 65536;

    private const OPTIONS = [
        RequestOptions::CONNECT_TIMEOUT => 5,
        RequestOptions::TIMEOUT => 20,
        RequestOptions::HTTP_ERRORS => true,
        RequestOptions::ALLOW_REDIRECTS => false,
    ];

    public function __construct(
        private GitLabDetailProvider $gitLab,
        private GitHubDetailProvider $gitHub,
        private ClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $before cursor of an older timeline page (see cursor()), "" for the newest
     * @throws FetchFailedException
     */
    public function load(ItemReference $reference, Connection $connection, string $before = ''): ItemDetail
    {
        $provider = $this->provider($connection);
        $requests = $provider->detailRequests($reference, $connection, $before);
        $promises = array_map(fn($request): PromiseInterface => $this->client->sendAsync($request, self::OPTIONS), $requests);

        $responses = [];
        foreach (Utils::settle($promises)->wait() as $key => $result) {
            if ($result['state'] === PromiseInterface::FULFILLED) {
                $responses[$key] = $result['value'];
                continue;
            }
            $responses[$key] = null;
            if ($key === 'detail') {
                $reason = $result['reason'] ?? null;
                throw $this->failure($connection, $reason instanceof \Throwable ? $reason : new \RuntimeException('Request rejected', 1764240401));
            }
        }

        try {
            return $provider->parseDetail($reference, $responses, $before);
        } catch (\UnexpectedValueException $exception) {
            throw new FetchFailedException(new FetchError($connection->name(), FetchError::FAILED, $exception->getMessage()), $exception);
        }
    }

    /**
     * @param array<mixed> $input raw form values
     * @throws FetchFailedException|InvalidActionException
     */
    public function perform(ItemReference $reference, Connection $connection, string $action, array $input): void
    {
        // Comments and threads of an older timeline page are checked against that page.
        $detail = $this->load($reference, $connection, self::cursor($input['page'] ?? null));
        if (!$detail->allows($action)) {
            throw new InvalidActionException('notAllowed', 1764240402);
        }
        if ($action === ItemAction::TOGGLE_TASK) {
            [$action, $parameters] = $this->toggledTask($detail, $connection, $input);
        } else {
            $parameters = $this->parameters($detail, $action, $input);
        }

        try {
            $response = $this->client->send($this->provider($connection)->actionRequest($detail, $action, $parameters, $connection), self::OPTIONS);
            $error = Responses::graphqlError($response);
            if ($error !== '') {
                throw new \RuntimeException('GraphQL: ' . $error, 1764240408);
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Action {action} on {project} {number} of connection {connection} failed: {message}', [
                'action' => $action,
                'project' => $reference->project,
                'number' => $reference->number,
                'connection' => $connection->uid,
                'message' => $throwable->getMessage(),
            ]);
            throw $this->failure($connection, $throwable);
        }
    }

    /**
     * A timeline page cursor from the request: GitLab and GitHub use base64 strings.
     */
    public static function cursor(mixed $value): string
    {
        return is_string($value) && preg_match('#^[A-Za-z0-9+/=_-]{1,512}$#', $value) === 1 ? $value : '';
    }

    /**
     * HTML of the Markdown as the provider renders it, not sanitised (see ProviderHtml).
     *
     * @throws FetchFailedException
     */
    public function preview(ItemReference $reference, Connection $connection, string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        $provider = $this->provider($connection);
        try {
            $response = $this->client->send(
                $provider->previewRequest($reference, $connection, mb_substr($markdown, 0, self::MAX_PREVIEW)),
                self::OPTIONS
            );
        } catch (\Throwable $throwable) {
            throw $this->failure($connection, $throwable);
        }
        return $provider->parsePreview($response);
    }

    /**
     * @return list<array{value: string, label: string}>
     * @throws FetchFailedException
     */
    public function suggest(ItemReference $reference, Connection $connection, string $kind, string $query): array
    {
        $provider = $this->provider($connection);
        $request = in_array($kind, self::SUGGESTION_KINDS, true)
            ? $provider->suggestionRequest($reference, $connection, $kind, mb_substr(trim($query), 0, 100))
            : null;
        if ($request === null) {
            return [];
        }
        try {
            return $provider->parseSuggestions($kind, $this->client->send($request, self::OPTIONS));
        } catch (\Throwable $throwable) {
            throw $this->failure($connection, $throwable);
        }
    }

    /**
     * @param array<mixed> $input
     * @return array<string, string>
     * @throws InvalidActionException
     */
    private function parameters(ItemDetail $detail, string $action, array $input): array
    {
        $text = static fn(string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        switch ($action) {
            case ItemAction::COMMENT:
            case ItemAction::REQUEST_CHANGES:
                $body = $text('body');
                if ($body === '' || mb_strlen($body) > ItemAction::MAX_COMMENT) {
                    throw new InvalidActionException('body', 1764240403);
                }
                return ['body' => $body];
            case ItemAction::EDIT_COMMENT:
            case ItemAction::DELETE_COMMENT:
                $comment = $detail->comment($text('comment'));
                if ($comment === null || !$comment[$action === ItemAction::EDIT_COMMENT ? 'canEdit' : 'canDelete']) {
                    throw new InvalidActionException('notAllowed', 1764240406);
                }
                if ($action === ItemAction::DELETE_COMMENT) {
                    return ['comment' => $comment['id']];
                }
                return ['comment' => $comment['id'], 'body' => $this->body($text('body'))];
            case ItemAction::REPLY:
            case ItemAction::RESOLVE:
            case ItemAction::UNRESOLVE:
                $thread = $detail->thread($text('thread'));
                $allowed = match ($action) {
                    ItemAction::REPLY => $thread !== null && $thread['canReply'],
                    ItemAction::RESOLVE => $thread !== null && $thread['canResolve'] && !$thread['resolved'],
                    default => $thread !== null && $thread['canResolve'] && $thread['resolved'],
                };
                if ($thread === null || !$allowed) {
                    throw new InvalidActionException('notAllowed', 1764240409);
                }
                $parameters = ['thread' => $thread['id'], 'replyTo' => $thread['replyTo']];
                return $action === ItemAction::REPLY ? $parameters + ['body' => $this->body($text('body'))] : $parameters;
            case ItemAction::REACT:
                $target = $detail->reactionTarget($text('subject'));
                $emoji = $text('emoji');
                $current = array_column($target['reactions'] ?? [], null, 'name');
                // The eight choices, or one the subject has already (GitLab allows any emoji).
                if ($target === null || !Reactions::isName($emoji) || (!isset(Reactions::CHOICES[$emoji]) && !isset($current[$emoji]))) {
                    throw new InvalidActionException('notAllowed', 1764240410);
                }
                return ['subject' => $target['subject'], 'emoji' => $emoji, 'remove' => ($current[$emoji]['reacted'] ?? false) ? '1' : ''];
            case ItemAction::ADD_ASSIGNEE:
            case ItemAction::REMOVE_ASSIGNEE:
                $user = $text('user');
                $assigned = in_array($user, $detail->assigneeNames(), true);
                if (!self::isUserName($user) || $assigned !== ($action === ItemAction::REMOVE_ASSIGNEE)) {
                    throw new InvalidActionException('user', 1764240413);
                }
                return ['user' => $user];
            case ItemAction::ADD_REVIEWER:
            case ItemAction::REMOVE_REVIEWER:
                $user = $text('user');
                $reviewer = $detail->reviewer($user);
                $valid = $action === ItemAction::ADD_REVIEWER
                    // A reviewer who has reviewed already can be asked again.
                    ? self::isUserName($user) && ($reviewer === null || $reviewer['state'] !== '')
                    : $reviewer !== null && $reviewer['removable'];
                if (!$valid) {
                    throw new InvalidActionException('user', 1764240414);
                }
                return ['user' => $user];
            case ItemAction::SET_MILESTONE:
                $milestone = $text('milestone');
                $known = in_array($milestone, array_column($detail->availableMilestones, 'id'), true);
                if ($milestone === $detail->milestone['id'] || ($milestone !== '' && !$known)) {
                    throw new InvalidActionException('milestone', 1764240415);
                }
                return ['milestone' => $milestone];
            case ItemAction::SET_DUE_DATE:
                $date = $text('dueDate');
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($date === $detail->dueDate || ($date !== '' && ($parsed === false || $parsed->format('Y-m-d') !== $date))) {
                    throw new InvalidActionException('dueDate', 1764240416);
                }
                return ['dueDate' => $date];
            case ItemAction::SET_CONFIDENTIAL:
                $confidential = $text('confidential') === '1';
                if ($confidential === $detail->confidential) {
                    throw new InvalidActionException('notAllowed', 1764240417);
                }
                return ['confidential' => $confidential ? '1' : ''];
            case ItemAction::MERGE:
            case ItemAction::ENABLE_AUTO_MERGE:
                $methods = $detail->merge->methods ?? [];
                $method = $text('method');
                if ($methods === [] ? $method !== '' : !in_array($method, $methods, true)) {
                    throw new InvalidActionException('notAllowed', 1764240418);
                }
                return [
                    'method' => $method,
                    'squash' => $text('squash') === '1' ? '1' : '',
                    'deleteBranch' => $text('deleteBranch') === '1' ? '1' : '',
                    // The commit the user saw: GitLab and GitHub refuse to merge if the branch moved on.
                    'sha' => $detail->merge->headSha ?? '',
                ];
            case ItemAction::APPROVE:
                return ['body' => mb_substr($text('body'), 0, ItemAction::MAX_COMMENT)];
            case ItemAction::EDIT:
                $title = $text('title');
                $description = is_string($input['description'] ?? null) ? $input['description'] : '';
                if ($title === '' || mb_strlen($title) > ItemAction::MAX_TITLE || strlen($description) > ItemAction::MAX_DESCRIPTION) {
                    throw new InvalidActionException('edit', 1764240404);
                }
                return ['title' => $title, 'description' => $description];
            case ItemAction::ADD_LABEL:
            case ItemAction::REMOVE_LABEL:
                $label = $text('label');
                $known = $action === ItemAction::ADD_LABEL ? array_column($detail->addableLabels(), 'name') : $detail->labelNames();
                if (!in_array($label, $known, true)) {
                    throw new InvalidActionException('label', 1764240405);
                }
                return ['label' => $label];
            default:
                return [];
        }
    }

    /**
     * The edit that ticks a task list item, made on the Markdown just loaded.
     *
     * @param array<mixed> $input subject ("" = description, else a comment id), index, checked
     * @return array{string, array<string, string>} action and its parameters
     * @throws InvalidActionException
     */
    private function toggledTask(ItemDetail $detail, Connection $connection, array $input): array
    {
        $subject = is_string($input['subject'] ?? null) ? $input['subject'] : '';
        $index = is_string($input['index'] ?? null) && ctype_digit($input['index']) ? (int)$input['index'] : -1;
        $checked = ($input['checked'] ?? '') === '1';
        $comment = $subject !== '' ? $detail->comment($subject) : null;
        if ($subject === '' ? !$detail->allows(ItemAction::EDIT) : ($comment === null || !$comment['canEdit'])) {
            throw new InvalidActionException('notAllowed', 1764240411);
        }
        $markdown = TaskList::toggle($comment['body'] ?? $detail->description, $index, $checked, $connection->provider === Provider::GitLab);
        if ($markdown === null) {
            throw new InvalidActionException('task', 1764240412);
        }
        return $comment === null
            ? [ItemAction::EDIT, ['title' => $detail->title, 'description' => $markdown]]
            : [ItemAction::EDIT_COMMENT, ['comment' => $comment['id'], 'body' => $markdown]];
    }

    /**
     * GitLab and GitHub user names ("jane.doe", "octo-cat", "renovate[bot]").
     */
    private static function isUserName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}(\[bot\])?$/', $name) === 1;
    }

    /**
     * @throws InvalidActionException
     */
    private function body(string $body): string
    {
        if ($body === '' || mb_strlen($body) > ItemAction::MAX_COMMENT) {
            throw new InvalidActionException('body', 1764240407);
        }
        return $body;
    }

    private function provider(Connection $connection): DetailProviderInterface
    {
        return match ($connection->provider) {
            Provider::GitLab => $this->gitLab,
            Provider::GitHub => $this->gitHub,
        };
    }

    /**
     * Like FetchError::fromThrowable(), plus the API's own message (e.g. "Pull Request is not mergeable").
     */
    private function failure(Connection $connection, \Throwable $throwable): FetchFailedException
    {
        $error = FetchError::fromThrowable($connection, $throwable);
        if ($throwable instanceof BadResponseException) {
            $payload = Responses::json($throwable->getResponse()) ?? [];
            $message = Payload::string($payload, 'message') ?: Payload::string($payload, 'error');
            if ($message === '' && is_array($payload['message'] ?? null)) {
                $message = (string)json_encode($payload['message'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($message !== '') {
                $error = new FetchError($error->connection, $error->reason, $error->detail . ': ' . mb_substr($message, 0, 300));
            }
        }
        return new FetchFailedException($error, $throwable);
    }
}
