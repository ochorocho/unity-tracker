<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class ExtensionSettings
{
    /**
     * @param list<string> $allowedHosts additional hosts besides gitlab.com and github.com, lower case
     */
    public function __construct(
        public array $allowedHosts = [],
        public int $cacheLifetime = 300,
    ) {}

    public static function fromExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): self
    {
        $configuration = $extensionConfiguration->get('unity_tracker');
        $configuration = is_array($configuration) ? $configuration : [];

        $hosts = array_map(
            static fn(string $host): string => strtolower(trim($host)),
            explode(',', (string)($configuration['allowedHosts'] ?? ''))
        );

        return new self(
            array_values(array_filter($hosts, static fn(string $host): bool => $host !== '')),
            max(0, (int)($configuration['cacheLifetime'] ?? 300)),
        );
    }
}
