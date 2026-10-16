<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\I18n\I18n;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use Sso\Model\Entity\IdentitySourceType;
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Session\SsoSession;
use Sso\Test\TestCase\FakeProvider;

class SsoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoEmergencyLogins',
        'plugin.Sso.SsoIdentities',
        'plugin.Sso.SsoSessionEvents',
        'plugin.Sso.Users',
        'plugin.Sso.LegacyUsers',
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
        $user = $this->userFor('subject-1');
        $this->assertSame('person@example.test', $user->email);
        $this->assertSame('Person One', $user->name);
        $this->assertStringStartsWith('!sso:', $user->password, 'an unusable password fills the NOT NULL column');
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
        $this->assertSame($local->id, $this->userFor('subject-1')->id);
        $this->assertSame('hashed', $users->get($local->id)->password, 'the local password is kept');
    }

    public function testCallbackDoesNotLinkOnUnverifiedEmail(): void
    {
        $this->createLocalUser('person@example.test');

        $this->completeSignIn(['email_verified' => false]);

        // A second row with the same email breaks the unique rule: refused.
        $this->assertRedirect('/sso');
        $this->assertNull($this->fetchTable('Sso.SsoIdentities')->findByExternalId('subject-1'));
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesUserOutsideRequiredGroup(): void
    {
        $this->completeSignIn(['groups' => ['other-staff']]);

        $this->assertRedirect('/sso');
        $this->assertSame(0, $this->fetchTable('Users')->find()->count());
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesTokenSignedWithUnpublishedKey(): void
    {
        $this->completeSignIn([], FakeProvider::foreignKey());

        $this->assertRedirect('/sso');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongNonce(): void
    {
        $this->completeSignIn(['nonce' => 'not-the-nonce']);

        $this->assertRedirect('/sso');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongAudience(): void
    {
        $this->completeSignIn(['aud' => 'another-client']);

        $this->assertRedirect('/sso');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testCallbackRefusesWrongState(): void
    {
        $this->get('/sso/login');
        $flow = $this->sessionValue(SsoSession::FLOW);
        $this->session([SsoSession::FLOW => $flow]);

        $this->get('/sso/callback?code=abc&state=forged');

        $this->assertRedirect('/sso');
        $this->assertNull($this->sessionValue('Auth'));
    }

    public function testBackchannelLogoutEndsSessions(): void
    {
        $this->completeSignIn([]);
        $identities = $this->fetchTable('Sso.SsoIdentities');
        $this->assertNull($identities->findByExternalId('subject-1')?->sessions_valid_from);

        // No CSRF token: the plugin answers before the application's CSRF check.
        $this->post('/sso/backchannel-logout', ['logout_token' => $this->provider->logoutToken()]);

        $this->assertResponseCode(200);
        $this->assertInstanceOf(DateTime::class, $identities->findByExternalId('subject-1')?->sessions_valid_from);
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
        $this->assertRedirect('/sso');
    }

    public function testSignInPageOffersRockSoftwareId(): void
    {
        $this->get('/sso?redirect=%2Fmessages');

        $this->assertResponseOk();
        $this->assertResponseContains('Sign in with Rock Software ID');
        $this->assertResponseContains('/sso/login?redirect=%2Fmessages');
    }

    public function testSignInPageExplainsMissingConfiguration(): void
    {
        $this->fetchTable('Sso.SsoSettings')->store(['is_enabled' => false]);

        $this->get('/sso');

        $this->assertResponseOk();
        $this->assertResponseContains('bin/cake sso configure');
        $this->assertResponseNotContains('Sign in with Rock Software ID');
    }

    public function testSignInPageIsTranslated(): void
    {
        I18n::setLocale('fi');
        try {
            $this->get('/sso');
        } finally {
            I18n::setLocale('en_US');
        }

        $this->assertResponseContains('Kirjaudu Rock Software ID:llä');
    }

    public function testSignedInPersonIsSentOnFromSignInPage(): void
    {
        $this->completeSignIn([]);
        $this->session($_SESSION);

        $this->get('/sso');

        $this->assertRedirect('/dashboard');
    }

    public function testSignInStampsSessionAndLastLogin(): void
    {
        $this->completeSignIn([]);

        $this->assertIsInt($this->sessionValue(SsoSession::ESTABLISHED_AT));
        $identity = $this->fetchTable('Sso.SsoIdentities')->findByExternalId('subject-1');
        $this->assertInstanceOf(DateTime::class, $identity?->last_login_at);
    }

    public function testDeletedUsersIdentityIsReplaced(): void
    {
        $this->completeSignIn([]);
        $this->fetchTable('Users')->deleteAll([]);
        // A fresh browser: the first token response and session are gone.
        Client::clearMockResponses();
        $this->provider->serveMetadata();
        $_SESSION = [];

        $this->completeSignIn([]);

        $this->assertRedirect('/messages');
        $this->assertSame(1, $this->fetchTable('Sso.SsoIdentities')->find()->count());
        $this->assertSame('person@example.test', $this->userFor('subject-1')->email);
    }

    public function testLegacyUsersColumnsAreKeptInStep(): void
    {
        Configure::write('Sso.userModel', 'LegacyUsers');
        Configure::write('Sso.lastLoginField', 'last_login_at');
        Configure::write('Sso.sessionsValidFromField', 'sessions_valid_from');
        try {
            $this->completeSignIn([]);
            $this->post('/sso/backchannel-logout', ['logout_token' => $this->provider->logoutToken()]);
        } finally {
            Configure::write('Sso.userModel', 'Users');
            Configure::write('Sso.lastLoginField', null);
            Configure::write('Sso.sessionsValidFromField', null);
        }

        $user = $this->fetchTable('LegacyUsers')->find()->firstOrFail();
        $this->assertSame('subject-1', $user->external_id);
        $this->assertSame(
            $this->fetchTable('Sso.IdentitySourceTypes')->idFor(IdentitySourceType::SSO),
            $user->identity_source_id,
        );
        $this->assertInstanceOf(DateTime::class, $user->synced_at);
        $this->assertInstanceOf(DateTime::class, $user->last_login_at);
        $this->assertInstanceOf(DateTime::class, $user->sessions_valid_from);
    }

    public function testAUserDeactivatedInTheApplicationStaysOut(): void
    {
        $users = $this->fetchTable('Users');
        $user = $this->createLocalUser('person@example.test');
        $user->set('is_active', false);
        $users->saveOrFail($user, ['validate' => false]);
        $identities = $this->fetchTable('Sso.SsoIdentities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));

        $this->completeSignIn([]);

        $this->assertRedirect('/sso');
        $this->assertFlashMessage('Your account has been deactivated in this application.');
        $this->assertFalse($users->get($user->id)->is_active, 'the provider does not reopen it');
        $types = $this->fetchTable('Sso.SsoSessionEvents')->find()
            ->contain(['SsoSessionEventTypes'])
            ->orderBy(['SsoSessionEvents.id' => 'ASC'])
            ->all()
            ->map(fn($event) => $event->sso_session_event_type->value)
            ->toList();
        $this->assertSame([SsoSessionEventType::USER_INACTIVE], $types);
    }

    public function testTheAppsClaimIsKeptForTheMenu(): void
    {
        $this->completeSignIn(['apps' => [
            ['name' => 'Messaging', 'url' => 'https://messaging.example.test/'],
            ['name' => 'Not https', 'url' => 'http://plain.example.test/'],
            ['name' => 'Script', 'url' => 'javascript:alert(1)'],
            'garbage',
        ]]);

        $this->assertSame(
            [['name' => 'Messaging', 'url' => 'https://messaging.example.test/']],
            $this->sessionValue(SsoSession::APPS),
        );
    }

    public function testARefusalOutsideTheGroupIsRecorded(): void
    {
        $this->completeSignIn(['groups' => ['other']]);

        $event = $this->fetchTable('Sso.SsoSessionEvents')->find()->contain(['SsoSessionEventTypes'])->firstOrFail();
        $this->assertSame(SsoSessionEventType::NOT_IN_GROUP, $event->sso_session_event_type->value);
        $this->assertSame('person@example.test', $event->email);
    }

    public function testDisabledSettingRefusesLogin(): void
    {
        $this->fetchTable('Sso.SsoSettings')->store(['is_enabled' => false]);

        $this->get('/sso/login');

        $this->assertRedirect('/sso');
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
        ], ['accessibleFields' => ['*' => true]]));
    }

    /**
     * @param string $subject The provider's subject identifier.
     * @return \Cake\Datasource\EntityInterface
     */
    private function userFor(string $subject): EntityInterface
    {
        $identity = $this->fetchTable('Sso.SsoIdentities')->findByExternalId($subject);
        $this->assertNotNull($identity, 'linked to ' . $subject);

        return $this->fetchTable('Users')->get($identity->user_id);
    }
}
