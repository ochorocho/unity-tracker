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
 * GitHub REST API, github.com or GitHub Enterprise Server.
 */
final readonly class GitHubProvider implements ProviderInterface
{
    public const PER_PAGE = 50;

    private const SEARCHES = [
        'reviewRequested' => 'is:open is:pr archived:false review-requested:@me',
        'assignedChange' => 'is:open is:pr archived:false assignee:@me',
        'authoredChange' => 'is:open is:pr archived:false author:@me',
        'assignedIssue' => 'is:open is:issue archived:false assignee:@me',
    ];

    public function accountRequest(Connection $connection): RequestInterface
    {
        return $this->request($connection, '/user');
    }

    public function parseAccount(array $payload): string
    {
        return Payload::string($payload, 'login');
    }

    public function queries(Connection $connection): array
    {
        // Unread notifications only (the API default).
        $queries = [new Query(Category::Todo, $this->request($connection, '/notifications', ['per_page' => self::PER_PAGE]))];
        foreach (self::SEARCHES as $category => $search) {
            $queries[] = new Query(Category::from($category), $this->request(
                $connection,
                '/search/issues',
                ['q' => $search, 'sort' => 'updated', 'order' => 'desc', 'per_page' => self::PER_PAGE]
            ));
        }
        return $queries;
    }

    public function parse(Query $query, array $payload, Connection $connection): array
    {
        if ($query->category === Category::Todo) {
            return array_map(fn(array $notification): Item => $this->notification($notification, $connection), Payload::list($payload));
        }
        return array_map(
            fn(array $entry): Item => $this->searchResult($query->category, $entry, $connection),
            Payload::list($payload, 'items')
        );
    }

    /**
     * @param array<mixed> $notification
     */
    private function notification(array $notification, Connection $connection): Item
    {
        $subjectType = Payload::string($notification, 'subject', 'type');
        $repositoryUrl = Payload::url($notification, 'repository', 'html_url');
        $url = $repositoryUrl;
        $reference = '';
        // The subject URL points to the API, e.g. …/repos/acme/app/pulls/7 → https://github.com/acme/app/pull/7
        if (preg_match('#/(issues|pulls)/(\d+)$#', Payload::string($notification, 'subject', 'url'), $matches) === 1) {
            $url = $repositoryUrl . ($matches[1] === 'pulls' ? '/pull/' : '/issues/') . $matches[2];
            $reference = '#' . $matches[2];
        }

        return new Item(
            Category::Todo,
            $connection->provider,
            $connection->name(),
            strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $subjectType)),
            Payload::string($notification, 'subject', 'title'),
            $url,
            Payload::string($notification, 'repository', 'full_name'),
            $reference,
            '',
            Payload::date($notification, 'updated_at'),
            [],
            Payload::string($notification, 'reason'),
        );
    }

    /**
     * @param array<mixed> $entry
     */
    private function searchResult(Category $category, array $entry, Connection $connection): Item
    {
        $repositoryUrl = Payload::string($entry, 'repository_url');
        $position = strpos($repositoryUrl, '/repos/');
        $number = Payload::string($entry, 'number');
        $labels = array_map(static fn(array $label): string => Payload::string($label, 'name'), Payload::list($entry, 'labels'));

        return new Item(
            $category,
            $connection->provider,
            $connection->name(),
            Payload::array($entry, 'pull_request') !== [] ? 'pull_request' : 'issue',
            Payload::string($entry, 'title'),
            Payload::url($entry, 'html_url'),
            $position !== false ? substr($repositoryUrl, $position + strlen('/repos/')) : '',
            $number !== '' ? '#' . $number : '',
            Payload::string($entry, 'user', 'login'),
            Payload::date($entry, 'updated_at'),
            array_values(array_filter($labels, static fn(string $label): bool => $label !== '')),
            '',
            Payload::bool($entry, 'draft'),
        );
    }

    /**
     * @param array<string, string|int> $query
     */
    private function request(Connection $connection, string $path, array $query = []): RequestInterface
    {
        $uri = $connection->apiBaseUrl() . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        return new Request('GET', $uri, [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $connection->token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
    }
}
