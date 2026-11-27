<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * A hub as EXT:mercure builds it: configured (with token factory) or not.
 */
final readonly class FakeHub implements HubInterface
{
    public function __construct(private bool $configured = true) {}

    public function getPublicUrl(): string
    {
        return 'https://example.com/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->configured ? new class implements TokenFactoryInterface {
            public function create(array $grants = [], array $additionalClaims = []): string
            {
                return 'token';
            }
        } : null;
    }

    public function publish(Update $update): string
    {
        throw new \LogicException('Updates go through the message bus', 1764500001);
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}
