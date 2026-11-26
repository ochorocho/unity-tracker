<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Filter;

/**
 * A named filter of one backend user, visible to the members of the groups it is shared with.
 */
final readonly class SavedFilter
{
    /**
     * @param list<int> $groups be_groups uids the filter is shared with
     */
    public function __construct(
        public int $uid,
        public int $owner,
        public string $title,
        public FilterDefinition $definition,
        public array $groups = [],
        public string $ownerName = '',
    ) {}

    public function isOwnedBy(int $backendUser): bool
    {
        return $this->owner === $backendUser;
    }
}
