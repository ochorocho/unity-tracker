<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\ImageProxy;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ImageProxyTest extends UnitTestCase
{
    /** @var list<array{url: string, authorization: string}> */
    private array $requests = [];

    /** @var array<string, Response> by URL */
    private array $responses = [];

    /**
     * @return array<string, array{Provider, string, string, bool}>
     */
    public static function urls(): array
    {
        return [
            'gitlab.com upload' => [Provider::GitLab, 'gitlab.com', 'https://gitlab.com/-/project/7/uploads/abc/a.png', true],
            'gitlab.com camo' => [Provider::GitLab, 'gitlab.com', 'https://user-content.gitlab-static.net/abc/def', true],
            'self-managed, other host' => [Provider::GitLab, 'git.example.com', 'https://user-content.gitlab-static.net/abc', false],
            'plain http' => [Provider::GitLab, 'gitlab.com', 'http://gitlab.com/a.png', false],
            'credentials in URL' => [Provider::GitLab, 'gitlab.com', 'https://user:pass@gitlab.com/a.png', false],
            'other port' => [Provider::GitLab, 'gitlab.com', 'https://gitlab.com:8443/a.png', false],
            'internal address' => [Provider::GitLab, 'gitlab.com', 'https://169.254.169.254/latest', false],
            'github camo' => [Provider::GitHub, 'github.com', 'https://camo.githubusercontent.com/abc', true],
            'github attachment' => [Provider::GitHub, 'github.com', 'https://private-user-images.githubusercontent.com/1/a.png?jwt=x', true],
            'external image' => [Provider::GitHub, 'github.com', 'https://img.shields.io/badge.svg', false],
            'enterprise media' => [Provider::GitHub, 'ghe.example.com', 'https://media.ghe.example.com/user/1/files/a.png', true],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyTheProvidersImageHostsAreAllowed(Provider $provider, string $host, string $url, bool $allowed): void
    {
        self::assertSame($allowed, $this->subject()->isAllowed(Fixture::connection($provider, $host), $url));
    }

    #[Test]
    public function gitLabUploadIsLoadedThroughTheApiWithTheToken(): void
    {
        $this->responses['https://gitlab.com/api/v4/projects/7/uploads/0123456789abcdef0123456789abcdef/a%20b.png'] = new Response(200, ['Content-Type' => 'image/png'], 'PNG');

        $image = $this->subject()->fetch(Fixture::connection(Provider::GitLab), 'https://gitlab.com/-/project/7/uploads/0123456789abcdef0123456789abcdef/a%20b.png');

        self::assertSame(['type' => 'image/png', 'body' => 'PNG'], $image);
        self::assertSame('Bearer secret-token', $this->requests[0]['authorization']);
    }

    #[Test]
    public function publicUploadIsLoadedWithoutTokenWhenTheApiRefuses(): void
    {
        $url = 'https://gitlab.com/acme/app/uploads/0123456789abcdef0123456789abcdef/a.png';
        $this->responses[$url] = new Response(200, ['Content-Type' => 'image/png'], 'PNG');

        $this->subject()->fetch(Fixture::connection(Provider::GitLab), $url);

        self::assertSame('https://gitlab.com/api/v4/projects/acme%2Fapp/uploads/0123456789abcdef0123456789abcdef/a.png', $this->requests[0]['url']);
        self::assertSame(['url' => $url, 'authorization' => ''], $this->requests[1]);
    }

    #[Test]
    public function thirdPartyImageHostGetsNoToken(): void
    {
        $this->responses['https://camo.githubusercontent.com/abc'] = new Response(200, ['Content-Type' => 'image/gif'], 'GIF');

        $this->subject()->fetch(Fixture::connection(Provider::GitHub), 'https://camo.githubusercontent.com/abc');

        self::assertSame('', $this->requests[0]['authorization']);
    }

    #[Test]
    public function redirectToAnotherHostIsNotFollowed(): void
    {
        $this->responses['https://camo.githubusercontent.com/abc'] = new Response(302, ['Location' => 'https://internal.example.com/secret']);

        $this->expectException(\RuntimeException::class);
        try {
            $this->subject()->fetch(Fixture::connection(Provider::GitHub), 'https://camo.githubusercontent.com/abc');
        } finally {
            self::assertCount(1, $this->requests);
        }
    }

    #[Test]
    public function htmlIsNotServedAsImage(): void
    {
        $this->responses['https://camo.githubusercontent.com/abc'] = new Response(200, ['Content-Type' => 'text/html'], '<script>');

        $this->expectException(\RuntimeException::class);
        $this->subject()->fetch(Fixture::connection(Provider::GitHub), 'https://camo.githubusercontent.com/abc');
    }

    private function subject(): ImageProxy
    {
        $handler = function (RequestInterface $request): PromiseInterface {
            $url = (string)$request->getUri();
            $this->requests[] = ['url' => $url, 'authorization' => $request->getHeaderLine('Authorization')];
            return Create::promiseFor($this->responses[$url] ?? new Response(404));
        };
        return new ImageProxy(new Client(['handler' => HandlerStack::create($handler)]));
    }
}
