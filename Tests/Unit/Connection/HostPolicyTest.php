<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Connection;

use Ochorocho\UnityTracker\Configuration\ExtensionSettings;
use Ochorocho\UnityTracker\Connection\HostPolicy;
use Ochorocho\UnityTracker\Connection\InvalidHostException;
use Ochorocho\UnityTracker\Connection\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HostPolicyTest extends UnitTestCase
{
    private HostPolicy $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new HostPolicy(new ExtensionSettings(['git.example.com', 'ghe.example.com:8443']));
    }

    #[Test]
    public function emptyInputResolvesToProviderDefault(): void
    {
        self::assertSame('github.com', $this->subject->resolve('', Provider::GitHub));
    }

    public static function acceptedInputDataProvider(): \Generator
    {
        yield 'default host' => ['gitlab.com', 'gitlab.com'];
        yield 'https url with slash' => ['https://GitLab.com/', 'gitlab.com'];
        yield 'configured host' => ['git.example.com', 'git.example.com'];
        yield 'configured host with port' => ['https://ghe.example.com:8443', 'ghe.example.com:8443'];
    }

    #[DataProvider('acceptedInputDataProvider')]
    #[Test]
    public function allowedHostIsNormalised(string $input, string $expected): void
    {
        self::assertSame($expected, $this->subject->resolve($input, Provider::GitLab));
    }

    public static function rejectedInputDataProvider(): \Generator
    {
        yield 'unknown host' => ['evil.example.com', InvalidHostException::NOT_ALLOWED];
        yield 'internal address' => ['127.0.0.1', InvalidHostException::NOT_ALLOWED];
        yield 'configured host on other port' => ['git.example.com:8080', InvalidHostException::NOT_ALLOWED];
        yield 'plain http' => ['http://gitlab.com', InvalidHostException::NOT_HTTPS];
        yield 'path' => ['https://gitlab.com/api/v4', InvalidHostException::MALFORMED];
        yield 'credentials' => ['https://user:pass@gitlab.com', InvalidHostException::MALFORMED];
        yield 'query' => ['https://gitlab.com?x=1', InvalidHostException::MALFORMED];
        yield 'garbage' => ['https://', InvalidHostException::MALFORMED];
    }

    #[DataProvider('rejectedInputDataProvider')]
    #[Test]
    public function invalidHostIsRejected(string $input, string $reason): void
    {
        try {
            $this->subject->resolve($input, Provider::GitLab);
            self::fail('Expected InvalidHostException');
        } catch (InvalidHostException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
