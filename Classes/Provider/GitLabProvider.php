<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Provider;

use GuzzleHttp\Psr7\Request;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Item\Item;
use Psr\Http\Message\RequestInterface;

/**
 * GitLab REST API v4, gitlab.com or self-managed.
 */
final readonly class GitLabProvider implements ProviderInterface
{
    public const PER_PAGE = 50;

    public function accountRequest(Connection $connection): RequestInterface
    {
        return $this->request($connection, '/user');
    }

    public function parseAccount(array $payload): string
    {
        return Payload::string($payload, 'username');
    }

    public function queries(Connection $connection): array
    {
        $openMergeRequests = ['state' => 'opened', 'order_by' => 'updated_at', 'per_page' => self::PER_PAGE];
        return [
            new Query(Category::Todo, $this->request($connection, '/todos', ['state' => 'pending', 'per_page' => self::PER_PAGE])),
            new Query(Category::ReviewRequested, $this->request(
                $connection,
                '/merge_requests',
                ['scope' => 'all', 'reviewer_username' => $connection->account] + $openMergeRequests
            )),
            new Query(Category::AssignedChange, $this->request($connection, '/merge_requests', ['scope' => 'assigned_to_me'] + $openMergeRequests)),
            new Query(Category::AuthoredChange, $this->request($connection, '/merge_requests', ['scope' => 'created_by_me'] + $openMergeRequests)),
            new Query(Category::AssignedIssue, $this->request(
                $connection,
                '/issues',
                ['state' => 'opened', 'scope' => 'assigned_to_me', 'order_by' => 'updated_at', 'per_page' => self::PER_PAGE]
            )),
        ];
    }

    public function parse(Query $query, array $payload, Connection $connection): array
    {
        $items = [];
        foreach (Payload::list($payload) as $entry) {
            $items[] = $query->category === Category::Todo
                ? $this->todo($entry, $connection)
                : $this->issuable($query->category, $entry, $connection);
        }
        return $items;
    }

    /**
     * @param array<mixed> $todo
     */
    private function todo(array $todo, Connection $connection): Item
    {
        $target = Payload::array($todo, 'target');
        $type = $this->type(Payload::string($todo, 'target_type'));
        $title = Payload::string($target, 'title');

        return new Item(
            Category::Todo,
            $connection->provider,
            $connection->name(),
            $type,
            $title !== '' ? $title : Payload::string($todo, 'body'),
            Payload::url($todo, 'target_url'),
            Payload::string($todo, 'project', 'path_with_namespace') ?: Payload::string($todo, 'group', 'full_path'),
            Payload::string($target, 'references', 'short') ?: $this->reference($type, Payload::string($target, 'iid')),
            Payload::string($todo, 'author', 'username'),
            Payload::date($todo, 'updated_at'),
            $this->labels($target),
            Payload::string($todo, 'action_name'),
            Payload::bool($target, 'draft'),
        );
    }

    /**
     * Merge request or issue from the list endpoints.
     *
     * @param array<mixed> $entry
     */
    private function issuable(Category $category, array $entry, Connection $connection): Item
    {
        $short = Payload::string($entry, 'references', 'short');
        $full = Payload::string($entry, 'references', 'full');
        $project = $short !== '' && str_ends_with($full, $short) ? substr($full, 0, -strlen($short)) : '';

        return new Item(
            $category,
            $connection->provider,
            $connection->name(),
            $category === Category::AssignedIssue ? 'issue' : 'merge_request',
            Payload::string($entry, 'title'),
            Payload::url($entry, 'web_url'),
            $project,
            $short,
            Payload::string($entry, 'author', 'username'),
            Payload::date($entry, 'updated_at'),
            $this->labels($entry),
            '',
            Payload::bool($entry, 'draft'),
        );
    }

    /**
     * "MergeRequest" → "merge_request", "DesignManagement::Design" → "design"
     */
    private function type(string $targetType): string
    {
        $name = substr((string)strrchr('::' . $targetType, ':'), 1);
        return strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function reference(string $type, string $iid): string
    {
        if ($iid === '') {
            return '';
        }
        return match ($type) {
            'merge_request' => '!' . $iid,
            'issue' => '#' . $iid,
            default => '',
        };
    }

    /**
     * @param array<mixed> $entry
     * @return list<string>
     */
    private function labels(array $entry): array
    {
        return array_values(array_filter(Payload::array($entry, 'labels'), is_string(...)));
    }

    /**
     * @param array<string, string|int> $query
     */
    private function request(Connection $connection, string $path, array $query = []): RequestInterface
    {
        $uri = $connection->apiBaseUrl() . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        return new Request('GET', $uri, [
            'Accept' => 'application/json',
            'PRIVATE-TOKEN' => $connection->token,
        ]);
    }
}
