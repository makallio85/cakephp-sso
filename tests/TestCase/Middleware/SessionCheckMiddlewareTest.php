<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Middleware;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Sso\Model\Entity\IdentitySourceType;
use Sso\Session\SsoSession;

/**
 * The test application adds SessionCheckMiddleware after authentication;
 * /dashboard stands in for any page behind sign-in.
 */
class SessionCheckMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
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

        $this->assertRedirect('https://app.example.test/users/login');
        $this->assertFlashMessage('Your session has ended. Please sign in again.');
    }

    public function testDeletedUserIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time());
        $this->fetchTable('Users')->deleteOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/users/login');
    }

    public function testSessionEndedByBackchannelLogoutIsSignedOut(): void
    {
        $user = $this->createUser();
        $this->signIn($user, time() - 60);
        $user->set('sessions_valid_from', DateTime::now());
        $this->fetchTable('Users')->saveOrFail($user);

        $this->get('/dashboard');

        $this->assertRedirect('https://app.example.test/users/login');
    }

    public function testSessionStartedAfterBackchannelLogoutPasses(): void
    {
        $user = $this->createUser();
        $user->set('sessions_valid_from', DateTime::now()->subMinutes(5));
        $this->fetchTable('Users')->saveOrFail($user);
        $this->signIn($user, time());

        $this->get('/dashboard');

        $this->assertResponseOk();
    }

    public function testAnonymousRequestIsUntouched(): void
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

        return $users->saveOrFail($users->newEntity([
            'email' => 'person@example.test',
            'name' => 'Person',
            'is_active' => true,
            'external_id' => 'subject-9',
            'identity_source_id' => $this->fetchTable('Sso.IdentitySourceTypes')->idFor(IdentitySourceType::SSO),
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
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
