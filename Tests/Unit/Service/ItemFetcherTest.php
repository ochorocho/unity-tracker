<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Provider\GitHubProvider;
use Ochorocho\UnityTracker\Provider\GitLabProvider;
use Ochorocho\UnityTracker\Provider\ProviderRegistry;
use Ochorocho\UnityTracker\Service\FetchError;
use Ochorocho\UnityTracker\Service\FetchFailedException;
use Ochorocho\UnityTracker\Service\ItemFetcher;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ItemFetcherTest extends UnitTestCase
{
    /**
     * Response per URL prefix; the first matching prefix wins, unknown URLs get "[]".
     *
     * @var array<string, Response|\Throwable>
     */
    private array $routes = [];

    /** @var list<string> */
    private array $requestedUrls = [];

    #[Test]
    public function itemsOfAllConnectionsAreMergedNewestFirst(): void
    {
        $this->routes = [
            'https://gitlab.com/api/v4/todos' => new Response(200, [], Fixture::json('GitLab/todos.json')),
            'https://api.github.com/notifications' => new Response(200, [], Fixture::json('GitHub/notifications.json')),
            'https://api.github.com/search/issues' => new Response(200, [], '{"items": []}'),
        ];

        $overview = $this->subject()->fetch([
            Fixture::connection(Provider::GitLab),
            $this->connection(2, Provider::GitHub),
        ]);

        self::assertSame([], $overview->errors);
        self::assertCount(6, $overview->byCategory(Category::Todo));
        self::assertSame(
            ['Fix login redirect', 'Add search', 'Crash on start', 'Q4 roadmap', 'v2.0.0', 'Pipeline failed'],
            array_map(static fn($item): string => $item->title, $overview->items)
        );
        // 5 GitLab + 5 GitHub lists + 2 GitHub notification details (the release has none)
        self::assertCount(12, $this->requestedUrls);
    }

    #[Test]
    public function notificationsAreEnrichedWithDetails(): void
    {
        $this->routes = [
            'https://api.github.com/notifications' => new Response(200, [], Fixture::json('GitHub/notifications.json')),
            'https://api.github.com/repos/acme/app/pulls/42' => new Response(200, [], Fixture::json('GitHub/pull_42.json')),
            'https://api.github.com/repos/acme/app/issues/9' => new Response(404, [], '{}'),
            'https://api.github.com/search/issues' => new Response(200, [], '{"items": []}'),
        ];

        $overview = $this->subject()->fetch([Fixture::connection(Provider::GitHub)]);

        self::assertSame([], $overview->errors);
        $byTitle = [];
        foreach ($overview->items as $item) {
            $byTitle[$item->title] = [$item->author, $item->labels];
        }
        self::assertSame(['octocat', ['bug', 'needs review']], $byTitle['Fix login redirect']);
        // A failed detail request keeps the item as it was.
        self::assertSame(['', []], $byTitle['Crash on start']);
    }

    #[Test]
    public function rejectedTokenIsReportedWithoutLosingOtherConnections(): void
    {
        $this->routes = [
            'https://gitlab.com/' => new Response(401, [], '{"message": "401 Unauthorized"}'),
            'https://api.github.com/notifications' => new Response(200, [], Fixture::json('GitHub/notifications.json')),
        ];

        $overview = $this->subject()->fetch([
            Fixture::connection(Provider::GitLab, label: 'Work GitLab'),
            $this->connection(2, Provider::GitHub),
        ]);

        self::assertEquals([new FetchError('Work GitLab', FetchError::UNAUTHORIZED, 'HTTP 401')], $overview->errors);
        self::assertCount(3, $overview->items);
    }

    #[Test]
    public function exhaustedRateLimitIsReported(): void
    {
        $this->routes = ['https://api.github.com/' => new Response(403, ['X-RateLimit-Remaining' => '0'], '{}')];

        $overview = $this->subject()->fetch([Fixture::connection(Provider::GitHub)]);

        self::assertSame(FetchError::RATE_LIMITED, $overview->errors[0]->reason);
    }

    #[Test]
    public function unreachableHostIsReported(): void
    {
        $this->routes = ['https://git.example.com/' => new ConnectException('Connection refused', new \GuzzleHttp\Psr7\Request('GET', '/'))];

        $overview = $this->subject()->fetch([Fixture::connection(Provider::GitLab, 'git.example.com')]);

        self::assertCount(1, $overview->errors);
        self::assertSame(FetchError::UNREACHABLE, $overview->errors[0]->reason);
        self::assertSame('git.example.com', $overview->errors[0]->detail);
    }

    #[Test]
    public function invalidJsonIsReported(): void
    {
        $this->routes = ['https://gitlab.com/api/v4/todos' => new Response(200, [], '<html>login</html>')];

        $overview = $this->subject()->fetch([Fixture::connection(Provider::GitLab)]);

        self::assertSame(FetchError::INVALID_RESPONSE, $overview->errors[0]->reason);
    }

    #[Test]
    public function connectionWithoutUsableTokenIsNotRequested(): void
    {
        $overview = $this->subject()->fetch([Fixture::connection(Provider::GitLab, token: '')]);

        self::assertSame(FetchError::MISSING_TOKEN, $overview->errors[0]->reason);
        self::assertSame([], $this->requestedUrls);
    }

    #[Test]
    public function identifyReturnsTheAccount(): void
    {
        $this->routes = ['https://api.github.com/user' => new Response(200, [], '{"login": "octocat"}')];

        self::assertSame('octocat', $this->subject()->identify(Fixture::connection(Provider::GitHub)));
    }

    #[Test]
    public function identifyFailsForRejectedToken(): void
    {
        $this->routes = ['https://gitlab.com/api/v4/user' => new Response(401, [], '{}')];

        try {
            $this->subject()->identify(Fixture::connection(Provider::GitLab));
            self::fail('Expected FetchFailedException');
        } catch (FetchFailedException $exception) {
            self::assertSame(FetchError::UNAUTHORIZED, $exception->error->reason);
        }
    }

    #[Test]
    public function identifyFailsWithoutAccountName(): void
    {
        $this->routes = ['https://gitlab.com/api/v4/user' => new Response(200, [], '{"id": 1}')];

        $this->expectException(FetchFailedException::class);
        $this->subject()->identify(Fixture::connection(Provider::GitLab));
    }

    private function subject(): ItemFetcher
    {
        $handler = function (RequestInterface $request): PromiseInterface {
            $url = (string)$request->getUri();
            $this->requestedUrls[] = $url;
            foreach ($this->routes as $prefix => $response) {
                if (str_starts_with($url, $prefix)) {
                    return $response instanceof \Throwable ? Create::rejectionFor($response) : Create::promiseFor($response);
                }
            }
            return Create::promiseFor(new Response(200, [], '[]'));
        };

        return new ItemFetcher(
            new ProviderRegistry(new GitLabProvider(), new GitHubProvider()),
            new Client(['handler' => HandlerStack::create($handler)]),
            new NullLogger(),
        );
    }

    private function connection(int $uid, Provider $provider): Connection
    {
        return new Connection($uid, 7, $provider, $provider->defaultHost(), '', 'token-' . $uid, 'me');
    }
}
