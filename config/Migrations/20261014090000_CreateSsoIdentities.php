<?php
declare(strict_types=1);

use Migrations\BaseMigration;
use Sso\Service\LegacyIdentities;

/**
 * The link between an application's user and their identity provider account,
 * kept in the plugin's own table so the application's `users` table needs no
 * columns of its own (1.2). No foreign key: the users table belongs to the
 * application, and nothing of the application's has to exist first.
 *
 * A 1.1 application kept the link on `users` (`external_id`, `synced_at`,
 * `last_login_at`, `sessions_valid_from`); those rows are copied here.
 */
class CreateSsoIdentities extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('sso_identities', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('external_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('synced_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_login_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('sessions_valid_from', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['external_id'], ['unique' => true, 'name' => 'sso_identities_external_id_unique'])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'sso_identities_user_id_unique'])
            ->create();

        LegacyIdentities::copy($this->getAdapter()->getConnection(), 'users');
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('sso_identities')->drop()->save();
    }
}
