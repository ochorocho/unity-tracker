<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\Provider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Loads images of descriptions and comments for the detail sheet.
 *
 * The backend's Content-Security-Policy allows images from its own origin only, and uploads of non-public
 * GitLab projects need the token. So images are fetched here and served by the module. Only https URLs on
 * the provider's own image hosts are fetched (no SSRF), the token is only sent to the connection's host.
 */
final readonly class ImageProxy
{
    public const MAX_BYTES = 10485760;

    public const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/bmp'];

    /** github.com renders external images through camo, attachments live on githubusercontent.com hosts. */
    private const GITHUB_HOSTS = [
        'github.com', 'camo.githubusercontent.com', 'private-user-images.githubusercontent.com', 'user-images.githubusercontent.com',
        'raw.githubusercontent.com', 'avatars.githubusercontent.com', 'objects.githubusercontent.com',
    ];

    public function __construct(
        private ClientInterface $client,
    ) {}

    public function isAllowed(Connection $connection, string $url): bool
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user'], $parts['pass'])
            && in_array(strtolower($parts['host'] ?? ''), $this->hosts($connection), true)
            && (!isset($parts['port']) || $connection->host === $parts['host'] . ':' . $parts['port']);
    }

    /**
     * @return array{type: string, body: string}
     * @throws \RuntimeException if the image is not allowed or cannot be loaded
     */
    public function fetch(Connection $connection, string $url): array
    {
        if (!$this->isAllowed($connection, $url)) {
            throw new \RuntimeException('Image host not allowed', 1764320001);
        }
        $api = $this->gitLabUploadApiUrl($connection, $url);
        if ($api !== null) {
            // Uploads of non-public projects: through the API with the token, else as anybody may see them.
            try {
                return $this->load($connection, $api, true);
            } catch (\RuntimeException) {
            }
        }
        return $this->load($connection, $url, $connection->provider === Provider::GitHub && parse_url($url, PHP_URL_HOST) === $connection->host);
    }

    /**
     * @return array{type: string, body: string}
     */
    private function load(Connection $connection, string $url, bool $authenticated): array
    {
        try {
            $response = $this->client->request('GET', $url, [
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT => 15,
                RequestOptions::HTTP_ERRORS => true,
                // Bearer: Guzzle drops the Authorization header when a redirect leaves the host.
                RequestOptions::HEADERS => $authenticated ? ['Authorization' => 'Bearer ' . $connection->token, 'Accept' => 'image/*'] : ['Accept' => 'image/*'],
                RequestOptions::ALLOW_REDIRECTS => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $target) use ($connection): void {
                        if (!$this->isAllowed($connection, (string)$target)) {
                            throw new \RuntimeException('Redirect to a host that is not allowed', 1764320002);
                        }
                    },
                ],
                RequestOptions::STREAM => true,
            ]);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Image not available: ' . $throwable->getMessage(), 1764320003, $throwable);
        }

        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if (!in_array($type, self::TYPES, true) || (int)$response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
            throw new \RuntimeException('Not an image or too large', 1764320004);
        }
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $body .= $stream->read(65536);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('Image too large', 1764320005);
        }
        return ['type' => $type, 'body' => $body];
    }

    /**
     * GitLab upload ("/-/project/123/uploads/<secret>/a.png" or "/group/project/uploads/<secret>/a.png") → API URL.
     */
    private function gitLabUploadApiUrl(Connection $connection, string $url): ?string
    {
        if ($connection->provider !== Provider::GitLab || parse_url($url, PHP_URL_HOST) !== explode(':', $connection->host)[0]) {
            return null;
        }
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/-/project/(\d+)/uploads/([0-9a-f]{32})/([^/]+)$#', $path, $matches) === 1) {
            $project = $matches[1];
        } elseif (preg_match('#^/((?:[\w.-]+/)+[\w.-]+)/uploads/([0-9a-f]{32})/([^/]+)$#', $path, $matches) === 1) {
            $project = rawurlencode($matches[1]);
        } else {
            return null;
        }
        return $connection->apiBaseUrl() . '/projects/' . $project . '/uploads/' . $matches[2] . '/' . $matches[3];
    }

    /**
     * @return list<string>
     */
    private function hosts(Connection $connection): array
    {
        $host = strtolower(explode(':', $connection->host)[0]);
        return match (true) {
            $connection->provider === Provider::GitLab && $host === 'gitlab.com' => ['gitlab.com', 'user-content.gitlab-static.net'],
            $connection->provider === Provider::GitLab => [$host],
            $host === 'github.com' => self::GITHUB_HOSTS,
            // GitHub Enterprise Server serves attachments from "media.<host>".
            default => [$host, 'media.' . $host],
        };
    }
}
