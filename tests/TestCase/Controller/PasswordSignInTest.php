<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Service\PasswordSignIn;
use Sso\Session\SsoSession;
use Sso\Test\TestCase\FakeProvider;

/**
 * The sign-in page's email and password form.
 */
class PasswordSignInTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoIdentities',
        'plugin.Sso.SsoSessionEvents',
        'plugin.Sso.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        Configure::write('Sso.passwordSignIn', true);
    }

    protected function tearDown(): void
    {
        Configure::write('Sso.passwordSignIn', false);
        parent::tearDown();
    }

    public function testTheFormIsOfferedOnlyWhereTheApplicationDeclaresPasswords(): void
    {
        $this->get('/sso');
        $this->assertResponseContains('name="password"');

        Configure::write('Sso.passwordSignIn', false);
        $this->get('/sso');
        $this->assertResponseNotContains('name="password"');
    }

    public function testAnApplicationKeepingItsOwnFormGetsNoneFromThePlugin(): void
    {
        Configure::write('Sso.passwordForm', 'application');
        try {
            $this->createUser('person@example.test', 'correct horse battery');

            $this->get('/sso');
            $this->assertResponseNotContains('name="password"');

            $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);
            $this->assertSessionNotHasKey('Auth');
            $this->assertSame([SsoSessionEventType::PASSWORD_SIGN_IN_OFF], $this->history());
        } finally {
            Configure::write('Sso.passwordForm', 'plugin');
        }
    }

    public function testTheFormIsGoneOncePasswordSignInIsTurnedOff(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');
        $this->connect();
        $this->fetchTable('Sso.SsoSettings')->setPasswordSignIn(false, (int)$user->get('id'));

        $this->get('/sso');
        $this->assertResponseContains('Sign in with Rock Software ID');
        $this->assertResponseNotContains('name="password"');

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);
        $this->assertRedirectContains('/sso');
        $this->assertSessionNotHasKey('Auth');
        $this->assertSame([SsoSessionEventType::PASSWORD_SIGN_IN_OFF], $this->history());
    }

    public function testACorrectPasswordSignsIn(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');

        $this->post('/sso/password', [
            'email' => 'Person@Example.test',
            'password' => 'correct horse battery',
            'redirect' => '/dashboard?tab=1',
        ]);

        $this->assertRedirect('/dashboard?tab=1');
        $this->assertSame($user->get('id'), Hash::get($_SESSION, 'Auth.id'));
        $this->assertSame(SsoSession::METHOD_PASSWORD, Hash::get($_SESSION, SsoSession::METHOD));
        $this->assertSame([SsoSessionEventType::SIGNED_IN], $this->history());
    }

    public function testAWrongPasswordIsRefusedAndRecorded(): void
    {
        $user = $this->createUser('person@example.test', 'correct horse battery');

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'wrong']);

        $this->assertRedirectContains('/sso');
        $this->assertFlashMessage('The email address or password is wrong.');
        $this->assertSessionNotHasKey('Auth');
        $event = $this->fetchTable('Sso.SsoSessionEvents')->find()->contain(['SsoSessionEventTypes'])->firstOrFail();
        $this->assertSame(SsoSessionEventType::WRONG_PASSWORD, $event->sso_session_event_type->value);
        $this->assertSame($user->get('id'), $event->user_id);
        $this->assertSame('person@example.test', $_SESSION['Sso']['passwordEmail'] ?? null, 'the address is kept for the form');
    }

    public function testAnUnknownAddressGetsTheSameAnswer(): void
    {
        $this->post('/sso/password', ['email' => 'nobody@example.test', 'password' => 'whatever']);

        $this->assertFlashMessage('The email address or password is wrong.');
        $this->assertSame([SsoSessionEventType::UNKNOWN_EMAIL], $this->history());
    }

    public function testAProviderUsersUnusablePasswordNeverMatches(): void
    {
        $this->createUser('person@example.test', null);

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => '!sso:x']);

        $this->assertSessionNotHasKey('Auth');
    }

    public function testADeactivatedUserIsRefused(): void
    {
        $this->createUser('person@example.test', 'correct horse battery', false);

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionNotHasKey('Auth');
        $this->assertFlashMessage('Your account has been deactivated in this application.');
    }

    public function testTooManyFailuresForOneAddressAreThrottled(): void
    {
        $this->createUser('person@example.test', 'correct horse battery');
        for ($i = 0; $i < PasswordSignIn::MAX_FAILURES_PER_EMAIL; $i++) {
            $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'wrong ' . $i]);
        }

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionNotHasKey('Auth');
        $this->assertFlashMessage(
            'Too many failed sign-ins. Try again in 15 minutes, or sign in with Rock Software ID.',
        );
        $this->assertSame(SsoSessionEventType::THROTTLED, $this->history()[0]);
    }

    public function testAnOutdatedHashIsReplaced(): void
    {
        $user = $this->createUser('person@example.test', null);
        $users = $this->fetchTable('Users');
        $user->set('password', password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]));
        $users->saveOrFail($user, ['validate' => false]);

        $this->post('/sso/password', ['email' => 'person@example.test', 'password' => 'correct horse battery']);

        $this->assertSessionHasKey('Auth');
        $hash = (string)$users->get($user->get('id'))->get('password');
        $this->assertFalse((new DefaultPasswordHasher())->needsRehash($hash));
    }

    public function testGetGoesBackToTheSignInPage(): void
    {
        $this->get('/sso/password');

        $this->assertRedirect('/sso');
    }

    /**
     * The history's event types, newest first.
     *
     * @return array<string>
     */
    private function history(): array
    {
        return $this->fetchTable('Sso.SsoSessionEvents')->find()
            ->contain(['SsoSessionEventTypes'])
            ->orderBy(['SsoSessionEvents.id' => 'DESC'])
            ->all()
            ->map(fn($event) => $event->sso_session_event_type->value)
            ->toList();
    }

    private function connect(): void
    {
        $this->fetchTable('Sso.SsoSettings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
    }

    private function createUser(string $email, ?string $password, bool $active = true): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => 'Person',
            'password' => $password === null ? '!sso:x' : (new DefaultPasswordHasher())->hash($password),
            'is_active' => $active,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
