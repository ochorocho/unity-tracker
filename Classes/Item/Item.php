<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Item;

use Ochorocho\UnityTracker\Connection\Provider;

/**
 * One issue, merge/pull request, to-do or notification, normalised across providers.
 */
final readonly class Item
{
    /**
     * @param string $type merge_request, pull_request, issue, commit, release, ...
     * @param string $reference short reference like "!12" or "#3", empty if there is none
     * @param list<string> $labels
     * @param string $reason why a to-do/notification exists, e.g. "mentioned", "review_requested"
     */
    public function __construct(
        public Category $category,
        public Provider $provider,
        public string $connection,
        public string $type,
        public string $title,
        public string $url,
        public string $project,
        public string $reference,
        public string $author,
        public \DateTimeImmutable $updatedAt,
        public array $labels = [],
        public string $reason = '',
        public bool $draft = false,
    ) {}
}
