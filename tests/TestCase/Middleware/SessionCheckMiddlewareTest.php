<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Sso\Session\SsoSession;

/**
 * The plugin inserts SessionCheckMiddleware itself; /dashboard stands in for
 * any page behind sign-in.
 */
class SessionCheckMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoIdentities',
        'plugin.Sso.Users',
    ];

    public function testCurrentSessionPasses(): void
    {
        $this->signIn($this->createUser(), time());

        $this->get('/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('dashboard');
    }

    public function testDeactivatedUserIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time());
        $user->set('is_active', false);
        $this->fetchTable('Users')->saveOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/sso');
        $this->assertFlashMessage('Your session has ended. Please sign in again.');
    }

    public function testDeletedUserIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time());
        $this->fetchTable('Users')->deleteOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/sso');
    }

    public function testSessionThePluginDidNotEstablishIsLeftAlone(): void
    {
        // An application's own sign-in (a password form, a token) is its to
        // manage: no Sso keys in the session, an identity the plugin may not
        // even recognise as a user.
        $user = $this->createUser();
        $this->session(['Auth' => $user]);
        $this->fetchTable('Users')->deleteOrFail($user);

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testLocalSessionIsNotEndedByAnOldTimestamp(): void
    {
        Configure::write('Sso.sessionsValidFromField', 'sessions_valid_from');
        try {
            $user = $this->createUser();
            $this->session(['Auth' => $user]);
            $this->endSessions(DateTime::now());

            $this->get('/dashboard');

            $this->assertResponseOk();
        } finally {
            Configure::write('Sso.sessionsValidFromField', null);
        }
    }

    public function testPasswordSessionEndsOncePasswordSignInIsTurnedOff(): void
    {
        Configure::write('Sso.passwordSignIn', true);
        try {
            $user = $this->createUser();
            $settings = $this->fetchTable('Sso.SsoSettings');
            $settings->store([
                'issuer_url' => 'https://id.example.test/application/o/app/',
                'client_id' => 'app-client',
                'client_secret' => 'the-secret',
                'is_enabled' => true,
            ]);

            $this->session(['Auth' => $user]);
            $this->get('/dashboard');
            $this->assertResponseOk('a password session runs while password sign-in is on');

            $settings->setPasswordSignIn(false, (int)$user->get('id'));
            $this->session(['Auth' => $user]);
            $this->get('/dashboard');
            $this->assertRedirect('https://app.example.test/sso');
            $this->assertFlashMessage('Password sign-in has been turned off. Please sign in with Rock Software ID.');

            // A session signed in through the provider carries on.
            $this->signIn($user, time());
            $this->get('/dashboard');
            $this->assertResponseOk();
        } finally {
            Configure::write('Sso.passwordSignIn', false);
        }
    }

    public function testPasswordSessionIsLeftAloneInAnApplicationWithoutAPasswordForm(): void
    {
        $user = $this->createUser();
        $settings = $this->fetchTable('Sso.SsoSettings');
        $settings->store([
            'issuer_url' => 'https://id.example.test/application/o/app/',
            'client_id' => 'app-client',
            'client_secret' => 'the-secret',
            'is_enabled' => true,
        ]);
        $settings->setPasswordSignIn(false, (int)$user->get('id'));

        $this->session(['Auth' => $user]);
        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testSessionEndedByBackchannelLogoutIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time() - 60);
        $this->endSessions(DateTime::now());

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/sso');
    }

    public function testSessionStartedAfterBackchannelLogoutPasses(): void
    {
        $user = $this->createUser();
        $this->endSessions(DateTime::now()->subMinutes(5));
        $this->signIn($user, time());

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testAnonymousRequestToSignInPagePasses(): void
    {
        $this->get('/sso');

        $this->assertResponseOk();
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createUser(): EntityInterface
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'password' => '!sso:x',
            'name' => 'Person',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $identities = $this->fetchTable('Sso.SsoIdentities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $user->id, 'external_id' => 'subject-9'],
            ['accessibleFields' => ['*' => true]],
        ));

        return $user;
    }

    /**
     * What a back-channel logout does.
     *
     * @param \Cake\I18n\DateTime $from Sessions started before this end.
     * @return void
     */
    private function endSessions(DateTime $from): void
    {
        $identities = $this->fetchTable('Sso.SsoIdentities');
        $identity = $identities->findByExternalId('subject-9');
        $this->assertNotNull($identity);
        $identity->set('sessions_valid_from', $from);
        $identities->saveOrFail($identity);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param int $establishedAt When the session started.
     * @return void
     */
    private function signIn(EntityInterface $user, int $establishedAt): void
    {
        // SsoSession's keys are dotted paths under `Sso`.
        $this->session([
            'Auth' => $user,
            'Sso' => ['authenticated' => true, 'method' => SsoSession::METHOD_OIDC, 'establishedAt' => $establishedAt],
        ]);
    }
}
