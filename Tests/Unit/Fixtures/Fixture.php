<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Fixtures;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\Provider;

final class Fixture
{
    public static function json(string $file): string
    {
        return (string)file_get_contents(__DIR__ . '/' . $file);
    }

    /**
     * @return array<mixed>
     */
    public static function payload(string $file): array
    {
        $payload = json_decode(self::json($file), true, 512, JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : [];
    }

    public static function connection(Provider $provider, string $host = '', string $token = 'secret-token', string $label = ''): Connection
    {
        return new Connection(1, 7, $provider, $host !== '' ? $host : $provider->defaultHost(), $label, $token, 'me');
    }
}
