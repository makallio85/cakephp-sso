<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Whether an application that has its own password form beside the identity
 * provider still accepts it, and who changed that last. On by default, so an
 * application keeps working exactly as before until someone turns it off.
 */
class AddPasswordSignInToSsoSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('sso_settings')
            ->addColumn('password_sign_in_enabled', 'boolean', [
                'null' => false,
                'default' => true,
                'after' => 'provisioning_open_until',
            ])
            ->addColumn('password_sign_in_changed_by', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'password_sign_in_enabled',
            ])
            ->addColumn('password_sign_in_changed_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'password_sign_in_changed_by',
            ])
            ->update();
    }
}
