<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Service;

use GuzzleHttp\Exception\BadResponseException;
use Ochorocho\UnityTracker\Connection\Connection;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Why a connection could not (completely) be loaded. The reason is a label key suffix.
 */
final readonly class FetchError
{
    public const MISSING_TOKEN = 'missingToken';
    public const UNAUTHORIZED = 'unauthorized';
    public const RATE_LIMITED = 'rateLimited';
    public const UNREACHABLE = 'unreachable';
    public const INVALID_RESPONSE = 'invalidResponse';
    public const FAILED = 'failed';

    public function __construct(
        public string $connection,
        public string $reason,
        public string $detail = '',
    ) {}

    public static function fromThrowable(Connection $connection, \Throwable $throwable): self
    {
        // Guzzle 7 (TYPO3 v13) and 8 (v14) both use these types.
        if ($throwable instanceof NetworkExceptionInterface) {
            return new self($connection->name(), self::UNREACHABLE, $connection->host);
        }
        if ($throwable instanceof BadResponseException) {
            $response = $throwable->getResponse();
            $status = $response->getStatusCode();
            $reason = match (true) {
                $response->getHeaderLine('x-ratelimit-remaining') === '0', $status === 429 => self::RATE_LIMITED,
                $status === 401, $status === 403 => self::UNAUTHORIZED,
                default => self::FAILED,
            };
            return new self($connection->name(), $reason, 'HTTP ' . $status);
        }
        if ($throwable instanceof \JsonException || $throwable instanceof \UnexpectedValueException) {
            return new self($connection->name(), self::INVALID_RESPONSE);
        }
        return new self($connection->name(), self::FAILED, $throwable->getMessage());
    }
}
