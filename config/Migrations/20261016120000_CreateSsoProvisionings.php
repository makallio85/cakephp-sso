<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Each time Rock Software ID connected the application through
 * `POST /sso/provision`: the token's id, which is accepted once, and what it
 * connected to. Rows are never updated or deleted.
 */
class CreateSsoProvisionings extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('sso_provisionings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('jti', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('issuer_url', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('remote_ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['jti'], ['unique' => true])
            ->addIndex(['created'])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('sso_provisionings')->drop()->save();
    }
}
