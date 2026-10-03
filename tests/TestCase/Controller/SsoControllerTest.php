<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use Sso\Model\Entity\IdentitySourceType;
use Sso\Session\SsoSession;
use Sso\Test\TestCase\FakeProvider;

class SsoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoEmergencyLogins',
        'plugin.Sso.Users',
    ];

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Client::clearMockResponses();
        $this->provider = new FakeProvider();
        $this->provider->serveMetadata();
        $this->fetchTable('Sso.SsoSettings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'required_group' => 'app-staff',
            'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        EventManager::instance()->off('Sso.afterLogin');
        parent::tearDown();
    }

    public function testLoginRedirectsToProviderWithPkce(): void
    {
        $this->get('/sso/login?redirect=/messages');

        $this->assertResponseCode(302);
        $location = $this->_response->getHeaderLine('Location');
        $this->assertStringStartsWith('https://id.example.test/application/o/authorize/?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $flow = $this->sessionValue(SsoSession::FLOW);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://app.example.test/sso/callback', $query['redirect_uri']);
        $this->assertSame('/messages', $flow['redirect']);
    }

    public function testLoginIgnoresOffSiteRedirect(): void
    {
        foreach (['https%3A%2F%2Fevil.example%2F', '%2F%2Fevil.example%2F', '%2F%5Cevil.example'] as $redirect) {
            $this->get('/sso/login?redirect=' . $redirect);

            $this->assertNull($this->sessionValue(SsoSession::FLOW)['redirect'], urldecode($redirect));
        }
    }

    public function testCallbackCreatesUserAndSignsIn(): void
    {
        $afterLogin = null;
        EventManager::instance()->on('Sso.afterLogin', function (EventInterface $event) use (&$afterLogin): void {
            $afterLogin = $event->getData('method');
        });

        $this->completeSignIn([]);

        $this->assertRedirect('/messages');
        $user = $this->fetchTable('Users')->find()->where(['external_id' => 'subject-1'])->firstOrFail();
        $this->assertSame('person@example.test', $user->email);
        $this->assertSame('Person One', $user->name);
        $this->assertSame(
            $this->fetchTable('Sso.IdentitySourceTypes')->idFor(IdentitySourceType::SSO),
            $user->identity_source_id,
        );
        $this->assertSame($user->id, $this->sessionValue('Auth')->id);
        $this->assertTrue($this->sessionValue(SsoSession::AUTHENTICATED));
        $this->assertSame(SsoSession::METHOD_OIDC, $afterLogin);
    }

    public function testCallbackLinksExistingLocalUserByVerifiedEmail(): void
    {
        $local = $this->createLocalUser('person@example.test');

        $this->completeSignIn([]);

        $users = $this->fetchTable('Users');
        $this->assertSame(1, $users->find()->count());
        $linked = $users->get($local->id);
        $this->assertSame('subject-1', $linked->external_id);
    }

    public function testCallbackDoesNotLinkOnUnverifiedEmail(): void
    {
        $this->createLocalUser('person@example.test');

        $this->completeSignIn(['email_verified' => false]);

        // A second row with the same email breaks the unique rule: refused.
        $this->assertRedirect('/users/login');
        $this->assertNull($this->fetchTable('Users')->find()->where(['external_id' => 'subject-1'])->first());
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesUserOutsideRequiredGroup(): void
    {
        $this->completeSignIn(['groups' => ['other-staff']]);

        $this->assertRedirect('/users/login');
        $this->assertSame(0, $this->fetchTable('Users')->find()->count());
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesTokenSignedWithUnpublishedKey(): void
    {
        $this->completeSignIn([], FakeProvider::foreignKey());

        $this->assertRedirect('/users/login');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongNonce(): void
    {
        $this->completeSignIn(['nonce' => 'not-the-nonce']);

        $this->assertRedirect('/users/login');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongAudience(): void
    {
        $this->completeSignIn(['aud' => 'another-client']);

        $this->assertRedirect('/users/login');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongState(): void
    {
        $this->get('/sso/login');
        $flow = $this->sessionValue(SsoSession::FLOW);
        $this->session([SsoSession::FLOW => $flow]);

        $this->get('/sso/callback?code=abc&state=forged');

        $this->assertRedirect('/users/login');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testBackchannelLogoutEndsSessions(): void
    {
        $this->completeSignIn([]);
        $user = $this->fetchTable('Users')->find()->where(['external_id' => 'subject-1'])->firstOrFail();
        $this->assertNull($user->sessions_valid_from);

        $this->post('/sso/backchannel-logout', ['logout_token' => $this->provider->logoutToken()]);

        $this->assertResponseCode(200);
        $user = $this->fetchTable('Users')->get($user->id);
        $this->assertInstanceOf(DateTime::class, $user->sessions_valid_from);
    }

    public function testBackchannelLogoutRejectsIdToken(): void
    {
        $this->post('/sso/backchannel-logout', ['logout_token' => $this->provider->idToken(['nonce' => 'x'])]);

        $this->assertResponseCode(400);
    }

    public function testEmergencyLinkSignsInOnceOnPost(): void
    {
        $local = $this->createLocalUser('admin@example.test');
        $token = $this->fetchTable('Sso.SsoEmergencyLogins')->issue($local->id, 15);

        $this->get('/sso/emergency/' . $token);
        $this->assertResponseOk();
        $this->assertNull($this->sessionValue('Auth'));

        $this->enableCsrfToken();
        $this->post('/sso/emergency/' . $token);
        $this->assertRedirect('/dashboard');
        $this->assertSame(SsoSession::METHOD_EMERGENCY, $this->sessionValue(SsoSession::METHOD));

        $this->post('/sso/emergency/' . $token);
        $this->assertRedirect('/users/login');
    }

    public function testDisabledSettingRefusesLogin(): void
    {
        $this->fetchTable('Sso.SsoSettings')->store(['is_enabled' => false]);

        $this->get('/sso/login');

        $this->assertRedirect('/users/login');
    }

    /**
     * A value from the session the last request ended with.
     *
     * @param string $key Session key.
     * @return mixed
     */
    private function sessionValue(string $key): mixed
    {
        // The integration session writes through to $_SESSION; the trait's
        // own snapshot is taken before the request runs.
        return Hash::get($_SESSION ?? [], $key);
    }

    /**
     * Runs login, then the callback with an ID token carrying these claims.
     *
     * @param array<string, mixed> $claims Claim overrides.
     * @param string|null $signingKey Sign with another key.
     * @return void
     */
    private function completeSignIn(array $claims, ?string $signingKey = null): void
    {
        $this->get('/sso/login?redirect=/messages');
        $flow = $this->sessionValue(SsoSession::FLOW);

        $this->provider->serveIdToken($this->provider->idToken($claims + ['nonce' => $flow['nonce']], $signingKey));
        $this->session([SsoSession::FLOW => $flow]);
        $this->get('/sso/callback?code=the-code&state=' . $flow['state']);
    }

    /**
     * @param string $email Email address.
     * @return \Cake\Datasource\EntityInterface
     */
    private function createLocalUser(string $email): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'password' => 'hashed',
            'name' => 'Local User',
            'is_active' => true,
            'identity_source_id' => $this->fetchTable('Sso.IdentitySourceTypes')->idFor(IdentitySourceType::LOCAL),
        ], ['accessibleFields' => ['*' => true]]));
    }
}
