<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The plugin's own tables.
 *
 * `identity_source_types` is the type table the consuming application's
 * `users.identity_source_id` points at: a user row is either a local account
 * or a cache of an identity provider account. Its two rows are system rows that
 * application code binds to by code.
 *
 * `sso_settings` holds the one provider connection. It lives in the database,
 * not the environment, and the client secret is stored encrypted.
 *
 * `sso_emergency_logins` holds single-use links issued from the command line
 * for signing in while the identity provider is unavailable. Only a hash of
 * each token is stored.
 */
class CreateSsoTables extends BaseMigration
{
    public function up(): void
    {
        $this->table('identity_source_types')
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('identity_source_types')
            ->insert([
                ['code' => 'local', 'label' => 'Local account', 'sort_order' => 10],
                ['code' => 'sso', 'label' => 'Identity provider', 'sort_order' => 20],
            ])
            ->save();

        $this->table('sso_settings')
            ->addColumn('issuer_url', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_secret', 'text', ['null' => false])
            ->addColumn('required_group', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('is_enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->create();

        $this->table('sso_emergency_logins')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('sso_emergency_logins')->drop()->save();
        $this->table('sso_settings')->drop()->save();
        $this->table('identity_source_types')->drop()->save();
    }
}
