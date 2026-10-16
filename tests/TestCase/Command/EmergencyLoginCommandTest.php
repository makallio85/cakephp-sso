<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

class EmergencyLoginCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoEmergencyLogins',
        'plugin.Sso.Users',
    ];

    public function testPrintsSingleUseLink(): void
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'admin@example.test',
            'password' => 'hashed',
            'name' => 'Admin',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true]]));

        $this->exec('sso emergency_login admin@example.test');

        $this->assertExitSuccess();
        $this->assertOutputRegExp('#https://app\.example\.test/sso/emergency/[0-9a-f]{64}#');
        $login = $this->fetchTable('Sso.SsoEmergencyLogins')->find()->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
    }

    public function testCreateMakesTheUserWhenDebugIsOn(): void
    {
        Configure::write('debug', true);
        try {
            $this->exec('sso emergency_login agent@example.test --create');
        } finally {
            Configure::write('debug', false);
        }

        $this->assertExitSuccess();
        $this->assertOutputContains('/sso/emergency/');
        $this->assertSame(1, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testCreateIsRefusedInProduction(): void
    {
        Configure::write('debug', false);

        $this->exec('sso emergency_login agent@example.test --create');

        $this->assertExitError();
        $this->assertErrorContains('debug off');
        $this->assertSame(0, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testUnknownEmailFails(): void
    {
        $this->exec('sso emergency_login nobody@example.test');

        $this->assertExitError();
    }
}
