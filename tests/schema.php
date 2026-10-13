<?php
declare(strict_types=1);

/**
 * `users` is a consuming application's table as it stands: nothing for the
 * plugin, and a password a provider-backed row never has. `legacy_users` is a
 * 1.1 application's, with the columns 1.2 moves into `sso_identities`.
 */
return [
    [
        'table' => 'users',
        'columns' => [
            'id' => ['type' => 'integer', 'null' => false],
            'email' => ['type' => 'string', 'length' => 190, 'null' => false],
            'password' => ['type' => 'string', 'length' => 255, 'null' => false],
            'name' => ['type' => 'string', 'length' => 100, 'null' => false],
            'is_active' => ['type' => 'boolean', 'null' => false, 'default' => true],
            'created' => ['type' => 'datetime', 'null' => false],
            'modified' => ['type' => 'datetime', 'null' => false],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
            'email' => ['type' => 'unique', 'columns' => ['email']],
        ],
    ],
    [
        'table' => 'legacy_users',
        'columns' => [
            'id' => ['type' => 'integer', 'null' => false],
            'email' => ['type' => 'string', 'length' => 190, 'null' => false],
            'name' => ['type' => 'string', 'length' => 100, 'null' => false],
            'is_active' => ['type' => 'boolean', 'null' => false, 'default' => true],
            'external_id' => ['type' => 'string', 'length' => 64, 'null' => true],
            'identity_source_id' => ['type' => 'integer', 'null' => true],
            'synced_at' => ['type' => 'datetime', 'null' => true],
            'sessions_valid_from' => ['type' => 'datetime', 'null' => true],
            'last_login_at' => ['type' => 'datetime', 'null' => true],
            'created' => ['type' => 'datetime', 'null' => true],
            'modified' => ['type' => 'datetime', 'null' => true],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ],
];
