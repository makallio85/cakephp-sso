<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The sign-in history: every sign-in, refused sign-in, sign-out and
 * administrator action on a user's access, kept for the retention period set
 * on the settings page. The password form's throttling counts from it too.
 *
 * `sso_session_event_types` says what happened, `sso_sign_in_method_types`
 * how a sign-in was attempted. Both hold system rows the plugin binds to by
 * value. No foreign key to `users`: that table belongs to the application.
 *
 * `sso_settings` gains the session limits (0 = off, so upgrading ends nobody's
 * session) and the history's retention.
 */
class CreateSsoSessionEvents extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->typeTable('sso_session_event_types', [
            ['value' => 'signed_in', 'label' => 'Signed in', 'sort_order' => 10],
            ['value' => 'wrong_password', 'label' => 'Wrong password', 'sort_order' => 20],
            ['value' => 'unknown_email', 'label' => 'Unknown email address', 'sort_order' => 30],
            ['value' => 'user_inactive', 'label' => 'User deactivated', 'sort_order' => 40],
            ['value' => 'not_in_group', 'label' => 'Not in the required group', 'sort_order' => 50],
            ['value' => 'throttled', 'label' => 'Too many attempts', 'sort_order' => 60],
            ['value' => 'password_sign_in_off', 'label' => 'Password sign-in is off', 'sort_order' => 70],
            ['value' => 'sign_in_failed', 'label' => 'Sign-in failed', 'sort_order' => 80],
            ['value' => 'signed_out', 'label' => 'Signed out', 'sort_order' => 90],
            ['value' => 'signed_out_by_provider', 'label' => 'Signed out by Rock Software ID', 'sort_order' => 100],
            ['value' => 'session_expired', 'label' => 'Session expired', 'sort_order' => 110],
            ['value' => 'sessions_ended', 'label' => 'Sessions ended by an administrator', 'sort_order' => 120],
            ['value' => 'user_deactivated', 'label' => 'Deactivated by an administrator', 'sort_order' => 130],
            ['value' => 'user_activated', 'label' => 'Activated by an administrator', 'sort_order' => 140],
        ]);
        $this->typeTable('sso_sign_in_method_types', [
            ['value' => 'oidc', 'label' => 'Rock Software ID', 'sort_order' => 10],
            ['value' => 'password', 'label' => 'Password', 'sort_order' => 20],
            ['value' => 'emergency', 'label' => 'Emergency link', 'sort_order' => 30],
        ]);

        $this->table('sso_session_events', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true, 'default' => null])
            ->addColumn('sso_session_event_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sso_sign_in_method_type_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'created'], ['name' => 'sso_session_events_user_created'])
            ->addIndex(['email', 'created'], ['name' => 'sso_session_events_email_created'])
            ->addIndex(['ip_address', 'created'], ['name' => 'sso_session_events_ip_created'])
            ->addIndex(['created'], ['name' => 'sso_session_events_created'])
            ->addForeignKey('sso_session_event_type_id', 'sso_session_event_types', 'id')
            ->addForeignKey('sso_sign_in_method_type_id', 'sso_sign_in_method_types', 'id')
            ->create();

        $this->table('sso_settings')
            ->addColumn('session_max_hours', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('session_idle_minutes', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('session_event_retention_days', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 180,
            ])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('sso_settings')
            ->removeColumn('session_max_hours')
            ->removeColumn('session_idle_minutes')
            ->removeColumn('session_event_retention_days')
            ->update();
        $this->table('sso_session_events')->drop()->save();
        $this->table('sso_sign_in_method_types')->drop()->save();
        $this->table('sso_session_event_types')->drop()->save();
    }

    /**
     * @param string $name The table.
     * @param array<array<string, mixed>> $rows Its system rows.
     * @return void
     */
    private function typeTable(string $name, array $rows): void
    {
        $this->table($name, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'signed' => false, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['value'], ['unique' => true])
            ->create();
        $this->table($name)->insert($rows)->save();
    }
}
