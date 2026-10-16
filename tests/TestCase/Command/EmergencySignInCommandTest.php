<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

class EmergencySignInCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.EmergencySignIns',
        'plugin.RockSoftware/Users.Users',
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

        $this->exec('rock_software_users emergency_sign_in admin@example.test');

        $this->assertExitSuccess();
        $this->assertOutputRegExp('#https://app\.example\.test/auth/emergency/[0-9a-f]{64}#');
        $login = $this->fetchTable('RockSoftware/Users.EmergencySignIns')->find()->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
    }

    public function testCreateMakesTheUserWhenDebugIsOn(): void
    {
        Configure::write('debug', true);
        try {
            $this->exec('rock_software_users emergency_sign_in agent@example.test --create');
        } finally {
            Configure::write('debug', false);
        }

        $this->assertExitSuccess();
        $this->assertOutputContains('/auth/emergency/');
        $this->assertSame(1, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testCreateIsRefusedInProduction(): void
    {
        Configure::write('debug', false);

        $this->exec('rock_software_users emergency_sign_in agent@example.test --create');

        $this->assertExitError();
        $this->assertErrorContains('debug off');
        $this->assertSame(0, $this->fetchTable('Users')->find()->where(['email' => 'agent@example.test'])->count());
    }

    public function testUnknownEmailFails(): void
    {
        $this->exec('rock_software_users emergency_sign_in nobody@example.test');

        $this->assertExitError();
    }
}
