<?php
declare(strict_types=1);

namespace RockSoftware\Users\Test\TestCase\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\Users\Controller\UsersController;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Session\SignInSession;

class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.RockSoftware/Users.Settings',
        'plugin.RockSoftware/Users.Identities',
        'plugin.RockSoftware/Users.SessionEvents',
        'plugin.RockSoftware/Users.Users',
    ];

    private EntityInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        Configure::write('RockSoftwareUsers.usersAccess', fn($identity): bool => $identity->get('email') === 'admin@example.test');
        $this->admin = $this->createUser('admin@example.test', 'Admin');
    }

    protected function tearDown(): void
    {
        Configure::write('RockSoftwareUsers.usersAccess', null);
        foreach ([UsersController::EVENT_LIST_COLUMNS, UsersController::EVENT_VIEW_SECTIONS, UsersController::EVENT_DEACTIVATED] as $name) {
            EventManager::instance()->off($name);
        }
        parent::tearDown();
    }

    public function testAccessIsTheApplicationsDecision(): void
    {
        $this->signIn($this->createUser('someone@example.test', 'Someone'));

        $this->get('/auth/users');

        $this->assertResponseCode(403);
    }

    public function testTheListShowsUsersTheirLinkAndLastSignIn(): void
    {
        $linked = $this->createUser('linked@example.test', 'Linked Person');
        $identities = $this->fetchTable('RockSoftware/Users.Identities');
        $identities->saveOrFail($identities->newEntity(
            ['user_id' => $linked->get('id'), 'external_id' => 'subject-1'],
            ['accessibleFields' => ['*' => true]],
        ));
        $this->fetchTable('RockSoftware/Users.SessionEvents')->record(SessionEventType::SIGNED_IN, [
            'user_id' => (int)$linked->get('id'),
            'method' => SignInSession::METHOD_OIDC,
        ]);
        $this->createUser('closed@example.test', 'Closed Person', false);
        $this->signIn($this->admin);

        $this->get('/auth/users');

        $this->assertResponseOk();
        $this->assertResponseContains('Linked Person');
        $this->assertResponseContains('Rock Software ID');
        $this->assertResponseNotContains('Closed Person', 'active users by default');

        $this->get('/auth/users?status=inactive');
        $this->assertResponseContains('Closed Person');
        $this->assertResponseNotContains('Linked Person');

        $this->get('/auth/users?status=all&q=linked');
        $this->assertResponseContains('Linked Person');
        $this->assertResponseNotContains('Closed Person');
    }

    public function testTheApplicationAddsItsOwnColumnsAndSections(): void
    {
        EventManager::instance()->on(UsersController::EVENT_LIST_COLUMNS, function (EventInterface $event): void {
            /** @var \ArrayObject $columns */
            $columns = $event->getData('columns');
            $columns[] = ['label' => 'Role', 'value' => fn($user): string => $user->get('email') === 'admin@example.test' ? 'Owner' : 'Viewer'];
        });
        EventManager::instance()->on(UsersController::EVENT_VIEW_SECTIONS, function (EventInterface $event): void {
            $this->assertInstanceOf(ArrayObject::class, $event->getData('sections'));
            $event->getData('sections')[] = ['title' => 'Permissions', 'element' => 'flash/info', 'data' => ['message' => 'Viewer of everything']];
        });
        $this->signIn($this->admin);

        $this->get('/auth/users');
        $this->assertResponseContains('<th scope="col">Role</th>');
        $this->assertResponseContains('Owner');

        $this->get('/auth/users/' . $this->admin->get('id'));
        $this->assertResponseContains('Permissions');
        $this->assertResponseContains('Viewer of everything');
    }

    public function testDeactivatingClosesAccessAndEndsTheSession(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $deactivated = null;
        EventManager::instance()->on(UsersController::EVENT_DEACTIVATED, function (EventInterface $event) use (&$deactivated): void {
            $deactivated = $event->getData('user')->get('email');
        });
        $this->signIn($this->admin);

        $this->post('/auth/users/' . $person->get('id') . '/deactivate');

        $this->assertRedirect('/auth/users/' . $person->get('id'));
        $this->assertFalse($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));
        $this->assertSame('person@example.test', $deactivated);

        $this->signIn($person);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');

        $this->signIn($this->admin);
        $this->get('/auth/users/' . $person->get('id'));
        $this->assertResponseContains('Deactivated by Admin');

        $this->post('/auth/users/' . $person->get('id') . '/activate');
        $this->assertTrue($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));
    }

    public function testNobodyDeactivatesThemselves(): void
    {
        $this->signIn($this->admin);

        $this->post('/auth/users/' . $this->admin->get('id') . '/deactivate');

        $this->assertFlashMessage('You cannot deactivate yourself.');
        $this->assertTrue($this->fetchTable('Users')->get($this->admin->get('id'))->get('is_active'));
    }

    public function testEndingSessionsSignsThePersonOut(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $this->signIn($this->admin);

        $this->post('/auth/users/' . $person->get('id') . '/end-sessions');
        $this->assertRedirect('/auth/users/' . $person->get('id'));

        $this->session([
            'Auth' => $person,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time() - 60],
        ]);
        $this->get('/dashboard');
        $this->assertRedirect('https://app.example.test/auth');
    }

    public function testChangesNeedPost(): void
    {
        $person = $this->createUser('person@example.test', 'Person');
        $this->signIn($this->admin);

        $this->get('/auth/users/' . $person->get('id') . '/deactivate');

        $this->assertResponseCode(405);
        $this->assertTrue($this->fetchTable('Users')->get($person->get('id'))->get('is_active'));
    }

    private function signIn(EntityInterface $user): void
    {
        $this->session([
            'Auth' => $user,
            'RockSoftwareUsers' => ['authenticated' => true, 'method' => SignInSession::METHOD_OIDC, 'establishedAt' => time()],
        ]);
    }

    private function createUser(string $email, string $name, bool $active = true): EntityInterface
    {
        $users = $this->fetchTable('Users');

        return $users->saveOrFail($users->newEntity([
            'email' => $email,
            'name' => $name,
            'password' => '!rsu:x',
            'is_active' => $active,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
    }
}
