<?php
declare(strict_types=1);

/**
 * The consuming application's users table, in the shape the plugin expects:
 * its own columns plus external_id, identity_source_id and synced_at.
 */
return [
    [
        'table' => 'users',
        'columns' => [
            'id' => ['type' => 'integer', 'null' => false],
            'email' => ['type' => 'string', 'length' => 190, 'null' => false],
            'password' => ['type' => 'string', 'length' => 255, 'null' => true],
            'name' => ['type' => 'string', 'length' => 100, 'null' => false],
            'is_active' => ['type' => 'boolean', 'null' => false, 'default' => true],
            'external_id' => ['type' => 'string', 'length' => 64, 'null' => true],
            'identity_source_id' => ['type' => 'integer', 'null' => false],
            'synced_at' => ['type' => 'datetime', 'null' => true],
            'sessions_valid_from' => ['type' => 'datetime', 'null' => true],
            'created' => ['type' => 'datetime', 'null' => false],
            'modified' => ['type' => 'datetime', 'null' => false],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
            'email' => ['type' => 'unique', 'columns' => ['email']],
            'external_id' => ['type' => 'unique', 'columns' => ['external_id']],
        ],
    ],
];
