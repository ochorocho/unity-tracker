<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Filter;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Groups a user may share filters with: their own groups (subgroups included), every group for admins.
 */
final readonly class ShareableGroups
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<int, string> uid => title, by title
     */
    public function forUser(BackendUserAuthentication $backendUser): array
    {
        $groups = [];
        if ($backendUser->isAdmin()) {
            $rows = $this->connectionPool->getQueryBuilderForTable('be_groups')
                ->select('uid', 'title')
                ->from('be_groups')
                ->executeQuery()
                ->fetchAllAssociative();
        } else {
            $rows = array_values($backendUser->userGroups);
        }
        foreach ($rows as $row) {
            $groups[(int)$row['uid']] = (string)$row['title'];
        }
        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        return $groups;
    }

    /**
     * @param list<int> $requested
     * @return list<int> the requested groups the user may share with
     */
    public function restrict(BackendUserAuthentication $backendUser, array $requested): array
    {
        return array_values(array_intersect(array_unique($requested), array_keys($this->forUser($backendUser))));
    }
}
