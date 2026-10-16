<?php
declare(strict_types=1);

namespace RockSoftware\Users\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Service\SignInHistory;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * The users page: who may use the application, how they last signed in, and
 * the sign-in history of each.
 *
 *   GET  /auth/users                    — the list (`?q=`, `?status=active|inactive|all`)
 *   GET  /auth/users/{id}               — one user and their history
 *   POST /auth/users/{id}/deactivate    — close the user's access here
 *   POST /auth/users/{id}/activate      — open it again
 *   POST /auth/users/{id}/end-sessions  — sign the user out everywhere in this application
 *
 * Access to the application itself is granted in Rock Software ID; this page
 * only closes it here, and roles stay the application's. An application adds
 * its own columns and sections through events, filling the `ArrayObject` in
 * the event data:
 *
 * - `RockSoftwareUsers.userListColumns` — `columns[] = ['label' => string, 'value' => fn(EntityInterface $user): string]`
 * - `RockSoftwareUsers.userViewSections` — `sections[] = ['title' => string, 'element' => string, 'data' => array]`,
 *   rendered with the application's element and `$user`
 *
 * It tells of each change through `RockSoftwareUsers.userDeactivated`, `RockSoftwareUsers.userActivated`
 * and `RockSoftwareUsers.sessionsEnded` (data: user, actor).
 *
 * Who may open it: `RockSoftwareUsers.usersAccess`, else `RockSoftwareUsers.settingsAccess`.
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class UsersController extends SettingsBaseController
{
    public const PAGE_SIZE = 50;

    public const EVENT_LIST_COLUMNS = 'RockSoftwareUsers.userListColumns';
    public const EVENT_VIEW_SECTIONS = 'RockSoftwareUsers.userViewSections';
    public const EVENT_DEACTIVATED = 'RockSoftwareUsers.userDeactivated';
    public const EVENT_ACTIVATED = 'RockSoftwareUsers.userActivated';
    public const EVENT_SESSIONS_ENDED = 'RockSoftwareUsers.sessionsEnded';

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!$this->components()->has('Flash')) {
            $this->loadComponent('Flash');
        }
        if (!$this->components()->has('Authentication')) {
            $this->loadComponent('Authentication.Authentication');
        }
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event The event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        if ($event->isStopped()) {
            return;
        }

        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return;
        }
        $access = Configure::read('RockSoftwareUsers.usersAccess')
            ?? Configure::read('RockSoftwareUsers.settingsAccess');
        $allowed = $access === null || (is_callable($access) && $access($identity, $this->getRequest()) === true);
        if (!$allowed) {
            throw new ForbiddenException(__d('rock_software_users', 'You may not manage users.'));
        }
    }

    /**
     * @return void
     */
    public function index(): void
    {
        $users = $this->users();
        $schema = $users->getSchema();
        $fields = UserProvisioner::fields();
        $hasName = $fields['name'] !== null && $schema->hasColumn($fields['name']);
        $hasActive = $fields['is_active'] !== null && $schema->hasColumn($fields['is_active']);

        $search = trim((string)$this->getRequest()->getQuery('q'));
        $status = (string)$this->getRequest()->getQuery('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $query = $users->find();
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $or = [$users->aliasField($fields['email']) . ' LIKE' => $like];
            if ($hasName) {
                $or[$users->aliasField((string)$fields['name']) . ' LIKE'] = $like;
            }
            $query->where(['OR' => $or]);
        }
        if ($hasActive && $status !== 'all') {
            $query->where([$users->aliasField((string)$fields['is_active']) => $status === 'active']);
        }
        $query->orderBy([$users->aliasField($hasName ? (string)$fields['name'] : $fields['email']) => 'ASC']);

        $page = $this->paginate($query, ['limit' => self::PAGE_SIZE, 'maxLimit' => self::PAGE_SIZE]);
        $ids = [];
        foreach ($page as $user) {
            /** @var \Cake\Datasource\EntityInterface $user */
            $ids[] = (int)$user->get('id');
        }

        $columns = new ArrayObject();
        EventManager::instance()->dispatch(new Event(self::EVENT_LIST_COLUMNS, $this, [
            'columns' => $columns,
            'users' => $page,
        ]));

        $this->set([
            'users' => $page,
            'fields' => $fields,
            'hasActive' => $hasActive,
            'search' => $search,
            'status' => $status,
            'linked' => $this->linkedUserIds($ids),
            'lastSignIns' => $this->fetchTable('RockSoftware/Users.SessionEvents')->latestSignIns($ids),
            'columns' => $columns->getArrayCopy(),
        ]);
    }

    /**
     * @param string $id The user.
     * @return void
     */
    public function view(string $id): void
    {
        $user = $this->user($id);
        /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
        $events = $this->fetchTable('RockSoftware/Users.SessionEvents');
        $history = $events->forUser((int)$user->get('id'));

        $actorIds = array_values(array_unique(array_filter(array_map(
            fn($event) => $event->actor_user_id,
            $history,
        ))));
        $actors = [];
        if ($actorIds !== []) {
            foreach ($this->users()->find()->where([$this->users()->aliasField('id') . ' IN' => $actorIds]) as $actor) {
                /** @var \Cake\Datasource\EntityInterface $actor */
                $actors[(int)$actor->get('id')] = UserProvisioner::displayName($actor);
            }
        }

        $sections = new ArrayObject();
        EventManager::instance()->dispatch(new Event(self::EVENT_VIEW_SECTIONS, $this, [
            'sections' => $sections,
            'user' => $user,
        ]));

        $fields = UserProvisioner::fields();
        $this->set([
            'user' => $user,
            'fields' => $fields,
            'hasActive' => $fields['is_active'] !== null
                && $this->users()->getSchema()->hasColumn($fields['is_active']),
            'isActive' => UserProvisioner::isActive($user),
            'isSelf' => $this->isSelf($user),
            'identity' => (new UserProvisioner())->identities()->findForUser((int)$user->get('id')),
            'lastSignIn' => $events->latestSignIns([(int)$user->get('id')])[(int)$user->get('id')] ?? null,
            'history' => $history,
            'actors' => $actors,
            'retentionDays' => $this->fetchTable('RockSoftware/Users.Settings')->sessionLimits()['retention_days'],
            'sections' => $sections->getArrayCopy(),
        ]);
    }

    /**
     * @param string $id The user.
     * @return \Cake\Http\Response|null
     */
    public function deactivate(string $id): ?Response
    {
        return $this->setActive($id, false);
    }

    /**
     * @param string $id The user.
     * @return \Cake\Http\Response|null
     */
    public function activate(string $id): ?Response
    {
        return $this->setActive($id, true);
    }

    /**
     * Ends every session of the user in this application on its next request.
     *
     * @param string $id The user.
     * @return \Cake\Http\Response|null
     */
    public function endSessions(string $id): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->user($id);
        (new SignInHistory())->record(SessionEventType::SESSIONS_ENDED, [
            'user_id' => (int)$user->get('id'),
            'actor_user_id' => $this->actorId(),
        ], $this->getRequest());
        $this->notify(self::EVENT_SESSIONS_ENDED, $user);
        $name = UserProvisioner::displayName($user);
        $this->Flash->success(__d('rock_software_users', '{0} is signed out of this application.', $name));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string $id The user.
     * @param bool $active Activate or deactivate.
     * @return \Cake\Http\Response|null
     */
    private function setActive(string $id, bool $active): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->user($id);
        $field = UserProvisioner::fields()['is_active'];
        if ($field === null || !$this->users()->getSchema()->hasColumn($field)) {
            throw new NotFoundException();
        }
        if (!$active && $this->isSelf($user)) {
            $this->Flash->error(__d('rock_software_users', 'You cannot deactivate yourself.'));

            return $this->redirect(['action' => 'view', $id]);
        }
        $user->set($field, $active, ['guard' => false]);
        $this->users()->saveOrFail($user, ['validate' => false]);
        (new SignInHistory())->record(
            $active ? SessionEventType::USER_ACTIVATED : SessionEventType::USER_DEACTIVATED,
            ['user_id' => (int)$user->get('id'), 'actor_user_id' => $this->actorId()],
            $this->getRequest(),
        );
        $this->notify($active ? self::EVENT_ACTIVATED : self::EVENT_DEACTIVATED, $user);
        $name = UserProvisioner::displayName($user);
        $this->Flash->success($active
            ? __d('rock_software_users', '{0} can sign in again.', $name)
            : __d('rock_software_users', '{0} is deactivated and signed out of this application.', $name));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function users(): Table
    {
        return (new UserProvisioner())->users();
    }

    /**
     * @param string $id The user.
     * @return \Cake\Datasource\EntityInterface
     */
    private function user(string $id): EntityInterface
    {
        $user = $this->users()->find()->where([$this->users()->aliasField('id') => (int)$id])->first();
        if (!$user instanceof EntityInterface) {
            throw new NotFoundException();
        }

        return $user;
    }

    /**
     * @param array<int> $ids Users.
     * @return array<int, bool>
     */
    private function linkedUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $linked = [];
        foreach ((new UserProvisioner())->identities()->find()->where(['user_id IN' => $ids]) as $identity) {
            /** @var \RockSoftware\Users\Model\Entity\Identity $identity */
            $linked[(int)$identity->user_id] = true;
        }

        return $linked;
    }

    /**
     * @return int|null
     */
    private function actorId(): ?int
    {
        $id = $this->Authentication->getIdentity()?->getIdentifier();

        return $id === null ? null : (int)$id;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return bool
     */
    private function isSelf(EntityInterface $user): bool
    {
        return $this->actorId() === (int)$user->get('id');
    }

    /**
     * @param string $name The event.
     * @param \Cake\Datasource\EntityInterface $user The user changed.
     * @return void
     */
    private function notify(string $name, EntityInterface $user): void
    {
        EventManager::instance()->dispatch(new Event($name, $this, [
            'user' => $user,
            'actor' => $this->Authentication->getIdentity()?->getOriginalData(),
        ]));
    }
}
