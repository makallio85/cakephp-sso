<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Client;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class SettingsControllerTest extends TestCase
{
    use HttpClientTrait;
    use IntegrationTestTrait;

    private const GOOD = 'https://id.example.test/application/o/app/';
    private const OTHER = 'https://id-other.example.test/application/o/app/';
    private const MISSING = 'https://id.example.test/application/o/apq/';

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoSettingChanges',
        'plugin.Sso.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        $this->enableCsrfToken();
        Configure::write(
            'Sso.settingsAccess',
            fn($identity): bool => $identity->get('email') === 'admin@example.test',
        );

        foreach ([self::GOOD, self::OTHER] as $issuer) {
            $this->mockClientGet($issuer . '.well-known/openid-configuration', $this->newClientResponse(
                200,
                ['Content-Type: application/json'],
                (string)json_encode([
                    'issuer' => $issuer,
                    'authorization_endpoint' => $issuer . 'authorize/',
                    'token_endpoint' => $issuer . 'token/',
                    'jwks_uri' => $issuer . 'jwks/',
                ]),
            ));
        }
        $this->mockClientGet(self::MISSING . '.well-known/openid-configuration', $this->newClientResponse(404));
    }

    protected function tearDown(): void
    {
        Configure::write('Sso.settingsAccess', null);
        Client::clearMockResponses();
        parent::tearDown();
    }

    public function testAnonymousRequestIsSentToSignIn(): void
    {
        $this->get('/sso/settings');

        $this->assertRedirectContains('/sso?redirect=%2Fsso%2Fsettings');
    }

    public function testAccessIsTheApplicationsDecision(): void
    {
        $this->signIn($this->createUser('someone@example.test'));
        $this->get('/sso/settings');
        $this->assertResponseCode(403);

        // No callable configured: anyone signed in, who is already in the
        // application's required group.
        Configure::write('Sso.settingsAccess', null);
        $this->signIn($this->createUser('admin@example.test'));
        $this->get('/sso/settings');
        $this->assertResponseOk();
    }

    public function testNotConfiguredPageShowsTheForm(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->get('/sso/settings');

        $this->assertResponseOk();
        $this->assertResponseContains('Not configured yet');
        $this->assertResponseContains('<input type="url" name="issuer_url"');
        $this->assertResponseContains('<input type="text" name="client_id"');
        $this->assertResponseContains('<input type="password" name="client_secret"');
        $this->assertResponseContains('https://app.example.test/sso/callback');
        $this->assertResponseNotContains('<select');
    }

    public function testSaveTestsTheServerThenStores(): void
    {
        $admin = $this->createUser('admin@example.test');
        $this->signIn($admin);

        $this->post('/sso/settings/update', [
            'issuer_url' => rtrim(self::GOOD, '/'),
            'client_id' => 'app',
            'client_secret' => 'the-secret',
            'required_group' => 'app-staff',
        ]);

        $this->assertRedirect('/sso/settings');
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $setting = $settings->current();
        $this->assertNotNull($setting);
        $this->assertSame(self::GOOD, $setting->issuer_url);
        $this->assertSame('app-staff', $setting->required_group);
        $this->assertTrue($setting->is_enabled);
        $this->assertNotSame('the-secret', $setting->client_secret, 'stored encrypted');
        $this->assertSame('the-secret', $settings->clientSecret($setting));
        /** @var \Sso\Model\Table\SsoSettingChangesTable $changes */
        $changes = $this->fetchTable('Sso.SsoSettingChanges');
        $this->assertSame($admin->id, $changes->latest()?->user_id);

        $this->get('/sso/settings');
        $this->assertResponseContains('Connected.');
        $this->assertResponseContains('Last changed by Admin');
        $this->assertResponseNotContains('the-secret');
    }

    public function testServerThatDoesNotAnswerIsNotSaved(): void
    {
        $this->signIn($this->createUser('admin@example.test'));
        $this->post('/sso/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 's1', 'required_group' => '',
        ]);

        $this->post('/sso/settings/update', [
            'issuer_url' => self::MISSING, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('answered 404');
        $this->assertResponseContains('is-invalid');
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $this->assertSame(self::GOOD, $settings->current()?->issuer_url);
        $this->assertSame(1, $this->fetchTable('Sso.SsoSettingChanges')->find()->count());
    }

    public function testBlankSecretKeepsTheStoredOne(): void
    {
        $this->signIn($this->createUser('admin@example.test'));
        $this->post('/sso/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 'kept', 'required_group' => 'x',
        ]);
        $this->post('/sso/settings/update', [
            'issuer_url' => self::OTHER, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $setting = $settings->current();
        $this->assertNotNull($setting);
        $this->assertSame(self::OTHER, $setting->issuer_url);
        $this->assertNull($setting->required_group);
        $this->assertSame('kept', $settings->clientSecret($setting));
    }

    public function testFirstSaveNeedsASecret(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->post('/sso/settings/update', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => '', 'required_group' => '',
        ]);

        $this->assertResponseContains('Enter the client secret');
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $this->assertNull($settings->current());
    }

    public function testTestConnectionSavesNothing(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->post('/sso/settings/test', [
            'issuer_url' => self::GOOD, 'client_id' => 'app', 'client_secret' => 'x', 'required_group' => '',
        ]);

        $this->assertResponseContains('The identity server answered. Nothing was saved.');
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $this->assertNull($settings->current());
    }

    public function testGetOnFormActionsReturnsToTheForm(): void
    {
        $this->signIn($this->createUser('admin@example.test'));

        $this->get('/sso/settings/test');
        $this->assertRedirect('/sso/settings');

        $this->get('/sso/settings/update');
        $this->assertRedirect('/sso/settings');
    }

    /**
     * @param string $email Email address.
     * @return \Cake\Datasource\EntityInterface
     */
    private function createUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => $email === 'admin@example.test' ? 'Admin' : 'Someone',
            'password' => '!sso:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return void
     */
    private function signIn(EntityInterface $user): void
    {
        $this->session([
            'Auth' => $user,
            'Sso' => ['authenticated' => true, 'method' => 'oidc', 'establishedAt' => time()],
        ]);
    }
}
