<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Provider;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Item\Item;
use Psr\Http\Message\RequestInterface;

/**
 * Builds API requests and turns decoded responses into items. No I/O happens here.
 */
interface ProviderInterface
{
    /**
     * Request for the account that owns the token; used to verify a new connection.
     */
    public function accountRequest(Connection $connection): RequestInterface;

    /**
     * @param array<mixed> $payload decoded response of accountRequest()
     */
    public function parseAccount(array $payload): string;

    /**
     * @return list<Query>
     */
    public function queries(Connection $connection): array;

    /**
     * @param array<mixed> $payload decoded response of the query's request
     * @return list<Item>
     */
    public function parse(Query $query, array $payload, Connection $connection): array;

    /**
     * Request for details a list response lacks (labels, author, draft), null if the item is complete.
     */
    public function detailRequest(Item $item, Connection $connection): ?RequestInterface;

    /**
     * @param array<mixed> $payload decoded response of detailRequest()
     */
    public function enrich(Item $item, array $payload): Item;
}
