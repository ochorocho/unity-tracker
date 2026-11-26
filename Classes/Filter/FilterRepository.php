<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Writes are scoped to the owner; reading also returns filters shared with one of the user's groups.
 */
final readonly class FilterRepository
{
    public const TABLE = 'tx_unitytracker_filter';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Own filters first, then those shared with the user, each by title.
     *
     * @param list<int> $groups all groups of the user, subgroups included
     * @return list<SavedFilter>
     */
    public function findVisibleFor(int $backendUser, array $groups): array
    {
        $queryBuilder = $this->queryBuilder();
        $visible = [$queryBuilder->expr()->eq('f.be_user', $queryBuilder->createNamedParameter($backendUser, ParameterType::INTEGER))];
        foreach ($groups as $group) {
            $visible[] = $queryBuilder->expr()->inSet('f.be_groups', $queryBuilder->createNamedParameter((string)$group));
        }
        $rows = $queryBuilder
            ->where($queryBuilder->expr()->or(...$visible))
            ->executeQuery()
            ->fetchAllAssociative();

        $filters = array_values(array_filter(array_map($this->map(...), $rows)));
        usort($filters, static fn(SavedFilter $a, SavedFilter $b): int => [!$a->isOwnedBy($backendUser), mb_strtolower($a->title)]
            <=> [!$b->isOwnedBy($backendUser), mb_strtolower($b->title)]);
        return $filters;
    }

    public function findOwned(int $uid, int $backendUser): ?SavedFilter
    {
        $queryBuilder = $this->queryBuilder();
        $row = $queryBuilder
            ->where(
                $queryBuilder->expr()->eq('f.uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('f.be_user', $queryBuilder->createNamedParameter($backendUser, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();
        return is_array($row) ? $this->map($row) : null;
    }

    /**
     * Inserts a filter with uid 0, otherwise updates it if the owner matches.
     *
     * @return int uid, 0 if there was nothing of the owner's to update
     */
    public function save(SavedFilter $filter): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = [
            'title' => $filter->title,
            'definition' => $filter->definition->toJson(),
            'be_groups' => implode(',', $filter->groups),
            'tstamp' => time(),
        ];
        if ($filter->uid === 0) {
            $connection->insert(self::TABLE, $data + ['be_user' => $filter->owner, 'crdate' => time()]);
            return (int)$connection->lastInsertId();
        }
        $updated = $connection->update(self::TABLE, $data, ['uid' => $filter->uid, 'be_user' => $filter->owner]);
        return $updated > 0 || $this->findOwned($filter->uid, $filter->owner) !== null ? $filter->uid : 0;
    }

    public function remove(int $uid, int $backendUser): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['uid' => $uid, 'be_user' => $backendUser]) > 0;
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('f.*', 'u.username', 'u.realName')
            ->from(self::TABLE, 'f')
            ->leftJoin('f', 'be_users', 'u', $queryBuilder->expr()->eq('u.uid', $queryBuilder->quoteIdentifier('f.be_user')));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ?SavedFilter
    {
        try {
            $definition = FilterDefinition::fromJson((string)$row['definition']);
        } catch (InvalidFilterException) {
            return null;
        }
        $realName = trim((string)($row['realName'] ?? ''));
        return new SavedFilter(
            (int)$row['uid'],
            (int)$row['be_user'],
            (string)$row['title'],
            $definition,
            array_values(array_map(intval(...), array_filter(explode(',', (string)$row['be_groups'])))),
            $realName !== '' ? $realName : (string)($row['username'] ?? ''),
        );
    }
}
