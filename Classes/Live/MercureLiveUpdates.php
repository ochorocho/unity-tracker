<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\Mercure\Http\SubscriptionUrl;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Live updates through the Mercure hub of EXT:mercure (ochorocho/typo3-mercure). Registered only when
 * that package is installed (Configuration/Services.php); off while its hub is not configured.
 *
 * Updates go through the message bus: EXT:mercure routes them to its transport, so a slow or absent hub never
 * delays a backend request.
 */
final readonly class MercureLiveUpdates implements LiveUpdates
{
    public function __construct(
        private HubInterface $hub,
        private MessageBusInterface $messageBus,
    ) {}

    public function isEnabled(): bool
    {
        // UnconfiguredHub of EXT:mercure has no token factory.
        return $this->hub->getFactory() !== null && $this->hub->getPublicUrl() !== '';
    }

    public function subscribe(ServerRequestInterface $request, string ...$topics): string
    {
        $grants = $request->getAttribute(SubscriberGrants::ATTRIBUTE);
        if (!$this->isEnabled() || !$grants instanceof SubscriberGrants || $topics === [] || !$this->isSameOrigin($request)) {
            return '';
        }
        $grants->authorize(...$topics);
        return SubscriptionUrl::build($this->hub, array_values($topics));
    }

    /**
     * The backend's CSP (connect-src 'self') only lets the EventSource reach a hub on the backend's origin, and
     * symfony/mercure refuses (throws) to create the subscriber cookie for another domain: a backend opened on
     * another host than the hub's gets no live updates.
     */
    private function isSameOrigin(ServerRequestInterface $request): bool
    {
        $hub = parse_url($this->hub->getPublicUrl());
        $uri = $request->getUri();
        $defaultPort = static fn(string $scheme): int => $scheme === 'https' ? 443 : 80;
        $hubScheme = strtolower($hub['scheme'] ?? '');
        return $hubScheme === strtolower($uri->getScheme())
            && strtolower($hub['host'] ?? '') === strtolower($uri->getHost())
            && ($hub['port'] ?? $defaultPort($hubScheme)) === ($uri->getPort() ?? $defaultPort($hubScheme));
    }

    public function publish(string $topic, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->messageBus->dispatch(new Update($topic, json_encode($data, JSON_THROW_ON_ERROR), private: true));
    }
}
