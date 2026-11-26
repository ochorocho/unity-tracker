<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use Ochorocho\UnityTracker\Provider\Payload;
use Psr\Http\Message\ResponseInterface;

/**
 * Decoding helpers shared by the detail providers.
 */
final class Responses
{
    /**
     * Decoded GraphQL answer; fails on transport errors and on GraphQL errors without data.
     *
     * @return array<mixed>
     */
    public static function graphql(?ResponseInterface $response): array
    {
        $payload = self::json($response);
        if ($payload === null) {
            throw new \UnexpectedValueException('No detail response', 1764240201);
        }
        $error = Payload::string($payload, 'errors', '0', 'message');
        if ($error !== '' && Payload::array($payload, 'data') === []) {
            throw new \UnexpectedValueException('GraphQL: ' . mb_substr($error, 0, 300), 1764240202);
        }
        return $payload;
    }

    /**
     * Error of a GraphQL mutation: top-level "errors", or GitLab's "errors" list in the mutation's payload.
     * "" for any other answer (REST answers have no such fields when they succeed).
     */
    public static function graphqlError(ResponseInterface $response): string
    {
        $payload = self::json($response) ?? [];
        $error = Payload::string($payload, 'errors', '0', 'message');
        if ($error !== '') {
            return mb_substr($error, 0, 300);
        }
        foreach (Payload::array($payload, 'data') as $result) {
            $error = is_array($result) ? Payload::string($result, 'errors', '0') : '';
            if ($error !== '') {
                return mb_substr($error, 0, 300);
            }
        }
        return '';
    }

    /**
     * @return array<mixed>|null null if there is no response or it is not a JSON object/array
     */
    public static function json(?ResponseInterface $response): ?array
    {
        if ($response === null) {
            return null;
        }
        try {
            $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }

    /**
     * "#a1b2c3" or "a1b2c3" → "#a1b2c3", anything else → "" (goes into a style attribute).
     */
    public static function color(string $value): string
    {
        $value = ltrim($value, '#');
        return preg_match('/^[0-9a-f]{6}$/i', $value) === 1 ? '#' . strtolower($value) : '';
    }
}
