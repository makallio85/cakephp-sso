<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Sso\Service\LegacyIdentities;

class LegacyIdentitiesTest extends TestCase
{
    protected array $fixtures = [
        'plugin.Sso.SsoIdentities',
        'plugin.Sso.LegacyUsers',
        'plugin.Sso.Users',
    ];

    public function testCopiesLinksOnceWithTheirTimes(): void
    {
        $users = $this->fetchTable('LegacyUsers');
        $linked = $users->saveOrFail($users->newEntity([
            'email' => 'linked@example.test',
            'name' => 'Linked',
            'external_id' => 'subject-1',
            'sessions_valid_from' => new DateTime('2026-10-01 12:00:00'),
            'last_login_at' => new DateTime('2026-10-02 08:30:00'),
        ], ['accessibleFields' => ['*' => true]]));
        $users->saveOrFail($users->newEntity(
            ['email' => 'local@example.test', 'name' => 'Local'],
            ['accessibleFields' => ['*' => true]],
        ));
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');

        $this->assertSame(1, LegacyIdentities::copy($connection, 'legacy_users'));
        $this->assertSame(0, LegacyIdentities::copy($connection, 'legacy_users'), 'idempotent');

        $identity = $this->fetchTable('Sso.SsoIdentities')->findByExternalId('subject-1');
        $this->assertNotNull($identity);
        $this->assertSame($linked->id, $identity->user_id);
        $this->assertSame('2026-10-01 12:00:00', $identity->sessions_valid_from?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 08:30:00', $identity->last_login_at?->format('Y-m-d H:i:s'));
    }

    public function testLeavesNoCachedDescriptionBehind(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $connection->cacheMetadata('_sso_test_metadata_');
        Cache::setConfig('_sso_test_metadata_', ['className' => 'Array']);
        try {
            LegacyIdentities::copy($connection, 'legacy_users');

            $this->assertNull(Cache::read($connection->configName() . '_legacy_users', '_sso_test_metadata_'));
        } finally {
            $connection->cacheMetadata(false);
            Cache::drop('_sso_test_metadata_');
        }
    }

    public function testTableWithoutLinksCopiesNothing(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');

        $this->assertSame(0, LegacyIdentities::copy($connection, 'users'));
        $this->assertSame(0, LegacyIdentities::copy($connection, 'no_such_table'));
    }
}
