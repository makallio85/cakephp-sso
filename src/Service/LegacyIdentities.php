<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Database\Connection;
use Cake\Database\Schema\Collection;
use Cake\I18n\DateTime;

/**
 * Moves a 1.1 application's links from its `users` table into
 * `sso_identities`: every row with an `external_id` becomes an identity, with
 * whichever of `synced_at`, `last_login_at` and `sessions_valid_from` the table
 * has. Rows already linked are left alone, so running it twice is harmless.
 */
final class LegacyIdentities
{
    /**
     * @param \Cake\Database\Connection $connection The connection.
     * @param string $usersTable The application's users table.
     * @return int How many identities were copied.
     */
    public static function copy(Connection $connection, string $usersTable): int
    {
        // Uncached: the application's next migration may drop these very
        // columns, and a cached description would outlive them (a year with
        // debug off), so the ORM would go on selecting columns that are gone.
        $schema = new Collection($connection);
        if (!in_array($usersTable, $schema->listTables(), true)) {
            return 0;
        }
        $columns = $schema->describe($usersTable)->columns();
        if (!in_array('external_id', $columns, true)) {
            return 0;
        }

        $select = ['id', 'external_id'];
        foreach (['synced_at', 'last_login_at', 'sessions_valid_from'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $rows = $connection->selectQuery($select, $usersTable)
            ->where(['external_id IS NOT' => null])
            ->execute()
            ->fetchAll('assoc');

        $now = DateTime::now()->format('Y-m-d H:i:s');
        $copied = 0;
        foreach ($rows as $row) {
            $exists = $connection->selectQuery(['id'], 'sso_identities')
                ->where(['OR' => ['user_id' => $row['id'], 'external_id' => $row['external_id']]])
                ->execute()
                ->fetch();
            if ($exists !== false) {
                continue;
            }
            $connection->insertQuery('sso_identities', [
                'user_id' => (int)$row['id'],
                'external_id' => (string)$row['external_id'],
                'synced_at' => $row['synced_at'] ?? null,
                'last_login_at' => $row['last_login_at'] ?? null,
                'sessions_valid_from' => $row['sessions_valid_from'] ?? null,
                'created' => $now,
                'modified' => $now,
            ])->execute();
            $copied++;
        }

        return $copied;
    }
}
