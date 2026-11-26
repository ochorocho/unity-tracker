<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Service;

use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Item\Item;

/**
 * Everything loaded for one backend user, newest first.
 */
final readonly class Overview
{
    /**
     * @param list<Item> $items
     * @param list<FetchError> $errors
     */
    public function __construct(
        public array $items,
        public array $errors,
        public \DateTimeImmutable $fetchedAt,
    ) {}

    /**
     * @return list<Item>
     */
    public function byCategory(Category $category): array
    {
        return array_values(array_filter($this->items, static fn(Item $item): bool => $item->category === $category));
    }
}
