<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Sso\Model\Entity\IdentitySourceType;

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
            'identity_source_id' => $this->fetchTable('Sso.IdentitySourceTypes')->idForCode(IdentitySourceType::LOCAL),
        ], ['accessibleFields' => ['*' => true]]));

        $this->exec('sso emergency_login admin@example.test');

        $this->assertExitSuccess();
        $this->assertOutputRegExp('#https://app\.example\.test/sso/emergency/[0-9a-f]{64}#');
        $login = $this->fetchTable('Sso.SsoEmergencyLogins')->find()->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
    }

    public function testUnknownEmailFails(): void
    {
        $this->exec('sso emergency_login nobody@example.test');

        $this->assertExitError();
    }
}
