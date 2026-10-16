<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Middleware;

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
