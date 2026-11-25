<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Connection;

use Ochorocho\UnityTracker\Connection\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProviderTest extends UnitTestCase
{
    public static function apiBaseUrlDataProvider(): \Generator
    {
        yield 'gitlab.com' => [Provider::GitLab, 'gitlab.com', 'https://gitlab.com/api/v4'];
        yield 'self-hosted GitLab' => [Provider::GitLab, 'git.example.com:8443', 'https://git.example.com:8443/api/v4'];
        yield 'github.com' => [Provider::GitHub, 'github.com', 'https://api.github.com'];
        yield 'GitHub Enterprise' => [Provider::GitHub, 'ghe.example.com', 'https://ghe.example.com/api/v3'];
    }

    #[DataProvider('apiBaseUrlDataProvider')]
    #[Test]
    public function apiBaseUrlDependsOnProviderAndHost(Provider $provider, string $host, string $expected): void
    {
        self::assertSame($expected, $provider->apiBaseUrl($host));
    }
}
