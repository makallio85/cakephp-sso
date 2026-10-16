<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Until when Rock Software ID may connect the application again through
 * `POST /sso/provision`. An application with no connection accepts the first
 * one; after that only inside a window an administrator opens on the
 * settings page. Null means closed.
 */
class AddProvisioningOpenUntilToSsoSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('sso_settings')
            ->addColumn('provisioning_open_until', 'datetime', ['null' => true, 'default' => null, 'after' => 'is_enabled'])
            ->update();
    }
}
