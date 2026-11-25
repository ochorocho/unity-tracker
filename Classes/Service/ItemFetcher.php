<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\RequestOptions;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Item\Item;
use Ochorocho\UnityTracker\Provider\ProviderInterface;
use Ochorocho\UnityTracker\Provider\ProviderRegistry;
use Ochorocho\UnityTracker\Provider\Query;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the requests of all connections concurrently. A failing request only costs its own items.
 */
final readonly class ItemFetcher
{
    private const OPTIONS = [
        RequestOptions::CONNECT_TIMEOUT => 5,
        RequestOptions::TIMEOUT => 15,
        RequestOptions::HTTP_ERRORS => true,
        RequestOptions::ALLOW_REDIRECTS => false,
    ];

    public function __construct(
        private ProviderRegistry $providers,
        private ClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<Connection> $connections
     */
    public function fetch(array $connections): Overview
    {
        /** @var array<int, array{Connection, ProviderInterface, Query}> $jobs */
        $jobs = [];
        $promises = [];
        $errors = [];
        foreach ($connections as $connection) {
            if (!$connection->hasToken()) {
                $errors[$connection->uid] = new FetchError($connection->name(), FetchError::MISSING_TOKEN);
                continue;
            }
            $provider = $this->providers->get($connection->provider);
            foreach ($provider->queries($connection) as $query) {
                $jobs[] = [$connection, $provider, $query];
                $promises[] = $this->client->sendAsync($query->request, self::OPTIONS);
            }
        }

        $items = [];
        foreach (Utils::settle($promises)->wait() as $index => $result) {
            [$connection, $provider, $query] = $jobs[$index];
            try {
                if ($result['state'] !== PromiseInterface::FULFILLED) {
                    throw $result['reason'] instanceof \Throwable ? $result['reason'] : new \RuntimeException('Request rejected', 1764072202);
                }
                array_push($items, ...$provider->parse($query, $this->decode($result['value']), $connection));
            } catch (\Throwable $throwable) {
                $this->logger->warning('Loading {category} of connection {connection} failed: {message}', [
                    'category' => $query->category->value,
                    'connection' => $connection->uid,
                    'message' => $throwable->getMessage(),
                ]);
                $errors[$connection->uid] ??= FetchError::fromThrowable($connection, $throwable);
            }
        }

        usort($items, static fn(Item $a, Item $b): int => $b->updatedAt <=> $a->updatedAt);
        return new Overview($items, array_values($errors), new \DateTimeImmutable());
    }

    /**
     * Verifies the token and returns the account name it belongs to.
     *
     * @throws FetchFailedException
     */
    public function identify(Connection $connection): string
    {
        $provider = $this->providers->get($connection->provider);
        try {
            $account = $provider->parseAccount($this->decode($this->client->send($provider->accountRequest($connection), self::OPTIONS)));
        } catch (\Throwable $throwable) {
            throw new FetchFailedException(FetchError::fromThrowable($connection, $throwable), $throwable);
        }
        if ($account === '') {
            throw new FetchFailedException(new FetchError($connection->name(), FetchError::INVALID_RESPONSE));
        }
        return $account;
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $response): array
    {
        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException('No response', 1764072203);
        }
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Response is not a JSON object or array', 1764072204);
        }
        return $payload;
    }
}
