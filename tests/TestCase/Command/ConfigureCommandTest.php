<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

class ConfigureCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = ['plugin.Sso.SsoSettings'];

    public function testStoresEncryptedSecret(): void
    {
        $this->exec('sso configure --issuer https://id.example.test/application/o/app --client-id app '
            . '--client-secret s3cret --required-group app-staff --enable');

        $this->assertExitSuccess();
        $settings = $this->fetchTable('Sso.SsoSettings');
        $setting = $settings->enabled();
        $this->assertNotNull($setting);
        $this->assertSame('https://id.example.test/application/o/app/', $setting->issuer_url);
        $this->assertNotSame('s3cret', $setting->client_secret);
        $this->assertSame('s3cret', $settings->clientSecret($setting));
    }

    public function testRejectsPlainHttpIssuer(): void
    {
        $this->exec('sso configure --issuer http://id.example.test/ --client-id app --client-secret s');

        $this->assertExitError();
    }
}
