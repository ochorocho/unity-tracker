<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Connection;

use Doctrine\DBAL\ParameterType;
use Ochorocho\UnityTracker\Security\TokenCipher;
use Ochorocho\UnityTracker\Security\TokenDecryptionException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Every method is scoped to one backend user: nobody reads or deletes another user's connections.
 */
final readonly class ConnectionRepository
{
    public const TABLE = 'tx_unitytracker_connection';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TokenCipher $cipher,
    ) {}

    /**
     * @return list<Connection>
     */
    public function findByBackendUser(int $backendUser): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($backendUser, ParameterType::INTEGER)))
            ->orderBy('crdate')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $connections = [];
        foreach ($rows as $row) {
            $provider = Provider::tryFrom((string)$row['provider']);
            if ($provider === null) {
                continue;
            }
            $connections[] = new Connection(
                (int)$row['uid'],
                (int)$row['be_user'],
                $provider,
                (string)$row['host'],
                (string)$row['label'],
                $this->decrypt((string)$row['token']),
                (string)$row['account'],
            );
        }
        return $connections;
    }

    public function add(Connection $connection): int
    {
        $databaseConnection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $databaseConnection->insert(self::TABLE, [
            'be_user' => $connection->backendUser,
            'provider' => $connection->provider->value,
            'host' => $connection->host,
            'label' => $connection->label,
            'account' => $connection->account,
            'token' => $this->cipher->encrypt($connection->token),
            'crdate' => time(),
        ]);
        return (int)$databaseConnection->lastInsertId();
    }

    public function remove(int $uid, int $backendUser): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['uid' => $uid, 'be_user' => $backendUser]) > 0;
    }

    private function decrypt(string $token): string
    {
        try {
            return $this->cipher->decrypt($token);
        } catch (TokenDecryptionException) {
            return '';
        }
    }
}
