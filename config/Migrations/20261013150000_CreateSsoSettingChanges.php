<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Who changed the identity provider connection, and when: one row per save on
 * the settings page. No foreign key, because the users table belongs to the
 * application. Created only if missing: an application that already kept a
 * table of this name and shape (id, user_id, created) keeps its rows.
 */
class CreateSsoSettingChanges extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('sso_setting_changes')) {
            return;
        }

        $this->table('sso_setting_changes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['created'])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('sso_setting_changes')->drop()->save();
    }
}
