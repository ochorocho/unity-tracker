<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Playwright;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A tiny GitLab for Playwright: answers requests to https://gitlab.fake.test inside TYPO3's Guzzle
 * stack (registered by additional.php for `X-Test-Run: 1` requests only). One user ("tester", api scope)
 * with one to-do: merge request acme/app!12. Its state lives in sys_registry of the test database,
 * specs reset it with `DELETE FROM sys_registry WHERE entry_namespace = 'unitytracker_fake'`.
 *
 * Discussions: jane's comment, tester's comment, a system note and jane's unresolved diff thread.
 * One older page (cursor OLDER) holds a system note.
 */
final class FakeGitLab
{
    public const HOST = 'gitlab.fake.test';
    /** 4×3 red PNG: an upload of acme/app, only with the token (like a non-public project). */
    private const UPLOAD = '/api/v4/projects/acme/app/uploads/0123456789abcdef0123456789abcdef/shot.png';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAEElEQVR4nGP4z8AARww4OQD1MQv1NXv7ggAAAABJRU5ErkJggg==';
    private const NAMESPACE = 'unitytracker_fake';
    private const OLDER = 'b2xkZXI=';
    private const MR = '/api/v4/projects/acme/app/merge_requests/12';

    /**
     * Guzzle middleware.
     */
    public static function middleware(): callable
    {
        return static fn(callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if ($request->getUri()->getHost() !== self::HOST) {
                return $handler($request, $options);
            }
            return Create::promiseFor((new self())->handle($request));
        };
    }

    public function handle(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = rawurldecode($request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $state = $this->state();
        if ($path === self::UPLOAD) {
            return $request->getHeaderLine('Authorization') === 'Bearer fake-token'
                ? new Response(200, ['Content-Type' => 'image/png'], (string)base64_decode(self::PNG))
                : new Response(404);
        }

        $response = match (true) {
            $path === '/api/v4/user' => ['id' => 42, 'username' => 'tester'],
            $path === '/api/v4/personal_access_tokens/self' => ['scopes' => ['api']],
            $path === '/api/v4/todos' => [$this->todo($state)],
            $path === '/api/v4/merge_requests', $path === '/api/v4/issues' => [],
            $path === '/api/v4/markdown' => ['html' => $this->markdown((string)($body['text'] ?? ''))],
            $path === '/api/v4/projects/acme/app/issues' => [['iid' => 3, 'title' => 'Crash on start']],
            $path === '/api/graphql' => $this->graphql($state, $body),
            $method === 'POST' && $path === self::MR . '/notes' => $this->addNote($state, null, (string)$body['body']),
            $method === 'POST' && preg_match('#^' . self::MR . '/discussions/([0-9a-f]+)/notes$#', $path, $matches) === 1
                => $this->addNote($state, $matches[1], (string)$body['body']),
            $method === 'PUT' && preg_match('#^' . self::MR . '/notes/(\d+)$#', $path, $matches) === 1
                => $this->editNote($state, (int)$matches[1], (string)$body['body']),
            $method === 'DELETE' && preg_match('#^' . self::MR . '/notes/(\d+)$#', $path, $matches) === 1
                => $this->deleteNote($state, (int)$matches[1]),
            $method === 'PUT' && $path === self::MR => $this->editMergeRequest($state, $body),
            default => null,
        };
        return $response === null
            ? new Response(404, ['Content-Type' => 'application/json'], '{"message": "404 Not Found"}')
            : new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($response));
    }

    /**
     * @return array{title: string, description: string, discussions: list<array{id: string, resolvable: bool, resolved: bool, notes: list<array{id: int, author: string, body: string, system?: bool, path?: string}>}>}
     */
    private function state(): array
    {
        $state = GeneralUtility::makeInstance(Registry::class)->get(self::NAMESPACE, 'state');
        return is_array($state) ? $state : [
            'title' => 'Fix login redirect',
            'description' => 'Fixes the **login** redirect.',
            'discussions' => [
                ['id' => 'a1', 'resolvable' => false, 'resolved' => false, 'notes' => [['id' => 101, 'author' => 'jane', 'body' => 'Please add a test.']]],
                ['id' => 'b2', 'resolvable' => false, 'resolved' => false, 'notes' => [['id' => 102, 'author' => 'tester', 'body' => 'Will do.']]],
                ['id' => 'c3', 'resolvable' => false, 'resolved' => false, 'notes' => [['id' => 103, 'author' => 'jane', 'body' => 'requested review from @tester', 'system' => true]]],
                ['id' => 'd4', 'resolvable' => true, 'resolved' => false, 'notes' => [['id' => 104, 'author' => 'jane', 'body' => 'Typo in the redirect.', 'path' => 'src/Login.php']]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        GeneralUtility::makeInstance(Registry::class)->set(self::NAMESPACE, 'state', $state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function todo(array $state): array
    {
        return [
            'id' => 55,
            'action_name' => 'review_requested',
            'target_type' => 'MergeRequest',
            'target_url' => 'https://' . self::HOST . '/acme/app/-/merge_requests/12',
            'updated_at' => '2026-11-20T10:00:00Z',
            'project' => ['path_with_namespace' => 'acme/app'],
            'author' => ['username' => 'jane'],
            'target' => ['iid' => 12, 'title' => $state['title'], 'references' => ['short' => '!12'], 'labels' => ['bug'], 'draft' => false],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function graphql(array $state, array $body): array
    {
        if (str_contains((string)($body['query'] ?? ''), 'discussionToggleResolve')) {
            foreach ($state['discussions'] as $index => $discussion) {
                if ('gid://gitlab/Discussion/' . $discussion['id'] === ($body['variables']['id'] ?? '')) {
                    $state['discussions'][$index]['resolved'] = (bool)$body['variables']['resolve'];
                    $this->save($state);
                }
            }
            return ['data' => ['discussionToggleResolve' => ['errors' => []]]];
        }
        if (str_contains((string)($body['query'] ?? ''), 'autocompleteUsers')) {
            $users = [['username' => 'jane', 'name' => 'Jane Doe'], ['username' => 'joe', 'name' => 'Joe Bloggs']];
            $search = strtolower((string)($body['variables']['search'] ?? ''));
            return ['data' => ['project' => ['autocompleteUsers' => array_values(array_filter(
                $users,
                static fn(array $user): bool => str_contains($user['username'], $search)
            ))]]];
        }
        $older = ($body['variables']['before'] ?? null) === self::OLDER;
        $discussions = $older
            ? [['id' => 'e5', 'resolvable' => false, 'resolved' => false, 'notes' => [['id' => 90, 'author' => 'jane', 'body' => 'created branch fix/login', 'system' => true]]]]
            : $state['discussions'];
        $discussions = array_map(fn(array $discussion): array => [
            'id' => 'gid://gitlab/Discussion/' . $discussion['id'],
            'resolvable' => $discussion['resolvable'],
            'resolved' => $discussion['resolved'],
            'userPermissions' => ['resolveNote' => true],
            'notes' => ['nodes' => array_map(fn(array $note): array => [
                'id' => 'gid://gitlab/Note/' . $note['id'],
                'body' => $note['body'],
                'bodyHtml' => $this->markdown($note['body']),
                'createdAt' => '2026-11-21T10:00:00Z',
                'system' => $note['system'] ?? false,
                'url' => 'https://' . self::HOST . '/acme/app/-/merge_requests/12#note_' . $note['id'],
                'author' => ['username' => $note['author']],
                'position' => isset($note['path']) ? ['filePath' => $note['path'], 'newLine' => 12, 'oldLine' => null] : null,
                'userPermissions' => ['adminNote' => $note['author'] === 'tester'],
            ], $discussion['notes'])],
        ], $discussions);
        return ['data' => [
            'currentUser' => ['id' => 'gid://gitlab/User/42', 'username' => 'tester'],
            'project' => [
                'labels' => ['nodes' => [['title' => 'bug', 'color' => '#d9534f'], ['title' => 'needs review', 'color' => '#428bca']]],
                'target' => [
                    'title' => $state['title'],
                    'state' => 'opened',
                    'webUrl' => 'https://' . self::HOST . '/acme/app/-/merge_requests/12',
                    'description' => $state['description'],
                    // GitLab lazy-loads images: a placeholder in src, the image in data-src.
                    'descriptionHtml' => $this->markdown($state['description'])
                        . '<p><img class="lazy" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="'
                        . ' data-src="/acme/app/uploads/0123456789abcdef0123456789abcdef/shot.png" alt="Screenshot"></p>'
                        . '<ul class="task-list" dir="auto"><li class="task-list-item"><input type="checkbox" data-checkbox-sourcepos="3:4-3:4"'
                        . ' class="task-list-item-checkbox" checked disabled aria-label="Check option: Tests"> Tests</li>'
                        . '<li class="task-list-item"><input type="checkbox" class="task-list-item-checkbox" disabled aria-label="Check option: Docs"> Docs</li></ul>',
                    'createdAt' => '2026-11-20T08:15:00Z',
                    'author' => ['username' => 'jane'],
                    'labels' => ['nodes' => [['title' => 'bug', 'color' => '#d9534f']]],
                    'assignees' => ['nodes' => []],
                    'discussions' => [
                        'pageInfo' => ['hasPreviousPage' => !$older, 'startCursor' => $older ? null : self::OLDER],
                        'nodes' => $discussions,
                    ],
                    'draft' => false,
                    'sourceBranch' => 'fix/login',
                    'targetBranch' => 'main',
                    'detailedMergeStatus' => 'mergeable',
                    'mergeable' => true,
                    'approvedBy' => ['nodes' => []],
                    'userPermissions' => [
                        'updateMergeRequest' => true, 'createNote' => true, 'canMerge' => true, 'canApprove' => true, 'adminMergeRequest' => true,
                    ],
                ],
            ],
        ]];
    }

    /**
     * A new discussion, or a reply when $discussion is given.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function addNote(array $state, ?string $discussion, string $body): ?array
    {
        $ids = array_column(array_merge(...array_column($state['discussions'], 'notes')), 'id');
        $note = ['id' => max([104, ...$ids]) + 1, 'author' => 'tester', 'body' => $body];
        if ($discussion === null) {
            $state['discussions'][] = ['id' => dechex($note['id']), 'resolvable' => false, 'resolved' => false, 'notes' => [$note]];
        } else {
            $index = array_search($discussion, array_column($state['discussions'], 'id'), true);
            if ($index === false) {
                return null;
            }
            $state['discussions'][$index]['notes'][] = $note;
        }
        $this->save($state);
        return $note;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function editNote(array $state, int $id, string $body): ?array
    {
        foreach ($state['discussions'] as $index => $discussion) {
            foreach ($discussion['notes'] as $noteIndex => $note) {
                if ($note['id'] === $id && $note['author'] === 'tester') {
                    $state['discussions'][$index]['notes'][$noteIndex]['body'] = $body;
                    $this->save($state);
                    return $state['discussions'][$index]['notes'][$noteIndex];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function deleteNote(array $state, int $id): ?array
    {
        foreach ($state['discussions'] as $index => $discussion) {
            foreach ($discussion['notes'] as $noteIndex => $note) {
                if ($note['id'] === $id && $note['author'] === 'tester') {
                    array_splice($state['discussions'][$index]['notes'], $noteIndex, 1);
                    $state['discussions'] = array_values(array_filter($state['discussions'], static fn(array $entry): bool => $entry['notes'] !== []));
                    $this->save($state);
                    return [];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function editMergeRequest(array $state, array $body): array
    {
        $state['title'] = (string)($body['title'] ?? $state['title']);
        $state['description'] = (string)($body['description'] ?? $state['description']);
        $this->save($state);
        return ['iid' => 12, 'title' => $state['title']];
    }

    /**
     * Just enough Markdown for the specs: paragraphs and **bold**.
     */
    private function markdown(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES);
        return '<p>' . preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html) . '</p>';
    }
}
