<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service;

use Cake\Core\Configure;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Table\IdentitiesTable;

/**
 * Turns a verified identity into the application's user row.
 *
 * A user deactivated in the application stays deactivated: signing in is
 * refused (`UserInactiveException`) until an administrator activates them on
 * the users page.
 *
 * The link lives in the plugin's `rock_software_users_identities` table, so the application's
 * `users` table needs nothing of its own: the plugin writes only the columns it
 * finds (email, name, active flag; names in `RockSoftwareUsers.fields`).
 *
 * The user is found through their identity's `external_id`. A user not yet
 * linked is matched on a verified email address, which is how existing accounts
 * move across at cutover. Otherwise a new user is created. Every sign-in
 * refreshes the email, name and active flag from the provider, which is the
 * master record.
 *
 * Fields the application needs beyond these are filled by a listener on
 * `RockSoftwareUsers.beforeUserSave`.
 */
class UserProvisioner
{
    use LocatorAwareTrait;

    public const EVENT_BEFORE_USER_SAVE = 'RockSoftwareUsers.beforeUserSave';

    /**
     * @param array<string, mixed> $claims Verified ID token claims.
     * @return \Cake\Datasource\EntityInterface
     */
    public function provision(array $claims): EntityInterface
    {
        $subject = (string)$claims['sub'];
        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new SignInException('The identity carries no email address.');
        }

        $users = $this->users();
        $schema = $users->getSchema();
        $fields = self::fields();
        $identities = $this->identities();

        $identity = $identities->findByExternalId($subject);
        $user = $identity === null ? null : $users->find()->where(['id' => $identity->user_id])->first();
        if ($identity !== null && $user === null) {
            // The application deleted the user; the stale link goes with it.
            $identities->deleteOrFail($identity);
            $identity = null;
        }
        if ($user === null && ($claims['email_verified'] ?? false) === true) {
            $linked = $identities->find()->select(['user_id']);
            $user = $users->find()
                ->where([$fields['email'] => $email, $users->aliasField('id') . ' NOT IN' => $linked])
                ->first();
        }

        if ($user !== null && !self::isActive($user)) {
            // Closed in this application; the provider's word does not reopen it.
            throw new UserInactiveException(sprintf('%s is deactivated in this application.', $email));
        }

        $isNew = $user === null;
        if ($isNew && !Configure::read('RockSoftwareUsers.createUsers')) {
            throw new SignInException(sprintf('No user matches %s and creating users is switched off.', $email));
        }
        $user ??= $users->newEmptyEntity();

        $name = $claims['name'] ?? null;
        $values = [$fields['email'] => $email];
        if (self::has($schema, $fields['name'])) {
            $values[(string)$fields['name']] = is_string($name) && $name !== '' ? $name : $email;
        }
        if ($isNew && self::has($schema, $fields['is_active'])) {
            $values[(string)$fields['is_active']] = true;
        }
        if ($isNew) {
            $values += $this->requiredDefaults($schema);
        }
        // One field at a time: set() with an array is deprecated from 5.2, and
        // patch() does not exist before it.
        foreach ($values as $field => $value) {
            $user->set($field, $value, ['guard' => false]);
        }

        EventManager::instance()->dispatch(new Event(self::EVENT_BEFORE_USER_SAVE, $this, [
            'user' => $user,
            'claims' => $claims,
            'isNew' => $isNew,
        ]));

        $connection = $users->getConnection();

        return $connection->transactional(function () use ($users, $user, $identity, $identities, $subject) {
            // The application's validation describes its own forms (a password on
            // create, for one); this data comes from the provider. Its rules, such
            // as a unique email address, still apply.
            $users->saveOrFail($user, ['validate' => false]);

            $identity ??= $identities->newEmptyEntity();
            $identity->set('user_id', (int)$user->get('id'), ['guard' => false]);
            $identity->set('external_id', $subject, ['guard' => false]);
            $identity->set('synced_at', DateTime::now(), ['guard' => false]);
            $identities->saveOrFail($identity);

            return $user;
        });
    }

    /**
     * Creates a user with no link to the identity provider, as a first
     * sign-in would (the application's `RockSoftwareUsers.beforeUserSave` listeners run, a
     * required password gets an unusable value). For an emergency sign-in on an
     * empty development database; the first real sign-in with the same verified
     * address links it.
     *
     * @param string $email The email address.
     * @return \Cake\Datasource\EntityInterface
     */
    public function createUser(string $email): EntityInterface
    {
        $users = $this->users();
        $schema = $users->getSchema();
        $fields = self::fields();
        $user = $users->newEmptyEntity();

        $values = [$fields['email'] => $email];
        if (self::has($schema, $fields['name'])) {
            $values[(string)$fields['name']] = $email;
        }
        if (self::has($schema, $fields['is_active'])) {
            $values[(string)$fields['is_active']] = true;
        }
        $values += $this->requiredDefaults($schema);
        foreach ($values as $field => $value) {
            $user->set($field, $value, ['guard' => false]);
        }

        EventManager::instance()->dispatch(new Event(self::EVENT_BEFORE_USER_SAVE, $this, [
            'user' => $user,
            'claims' => ['email' => $email, 'name' => $email],
            'isNew' => true,
        ]));

        return $users->saveOrFail($user, ['validate' => false]);
    }

    /**
     * Finds the user a subject identifier belongs to.
     *
     * @param string $subject The provider's subject identifier.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findBySubject(string $subject): ?EntityInterface
    {
        $identity = $this->identities()->findByExternalId($subject);

        /** @var \Cake\Datasource\EntityInterface|null */
        return $identity === null ? null : $this->users()->find()->where(['id' => $identity->user_id])->first();
    }

    /**
     * @return \Cake\ORM\Table
     */
    public function users(): Table
    {
        return $this->fetchTable((string)Configure::read('RockSoftwareUsers.userModel', 'Users'));
    }

    /**
     * @return \RockSoftware\Users\Model\Table\IdentitiesTable
     */
    public function identities(): IdentitiesTable
    {
        /** @var \RockSoftware\Users\Model\Table\IdentitiesTable */
        return $this->fetchTable('RockSoftware/Users.Identities');
    }

    /**
     * Column names on the users table, overridable through `RockSoftwareUsers.fields`. A null
     * or missing column is not written.
     *
     * @return array{email: string, name: string|null, is_active: string|null, password: string|null}
     */
    public static function fields(): array
    {
        /** @var array{email: string, name: string|null, is_active: string|null, password: string|null} */
        return array_merge([
            'email' => 'email',
            'name' => 'name',
            'is_active' => 'is_active',
            'password' => 'password',
        ], (array)Configure::read('RockSoftwareUsers.fields', []));
    }

    /**
     * Whether the user may sign in: true unless the active column exists and
     * is off.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return bool
     */
    public static function isActive(EntityInterface $user): bool
    {
        $field = self::fields()['is_active'];
        if ($field === null || !(new self())->users()->getSchema()->hasColumn($field)) {
            return true;
        }

        return (bool)$user->get($field);
    }

    /**
     * The name shown for a user: the name column, else the email address.
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @return string
     */
    public static function displayName(EntityInterface $user): string
    {
        $fields = self::fields();
        $name = $fields['name'] === null ? null : $user->get($fields['name']);

        return is_string($name) && $name !== '' ? $name : (string)$user->get($fields['email']);
    }

    /**
     * A value for every column a new row cannot be inserted without, other than
     * the ones written above: a `password` gets an unusable value, which no
     * password check ever matches. Anything else is the application's to fill,
     * on `RockSoftwareUsers.beforeUserSave`.
     *
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The users table.
     * @return array<string, mixed>
     */
    private function requiredDefaults(TableSchemaInterface $schema): array
    {
        $values = [];
        foreach (array_filter([self::fields()['password']]) as $column) {
            $definition = $schema->getColumn($column);
            if ($definition !== null && !$definition['null'] && $definition['default'] === null) {
                $values[$column] = '!rsu:' . bin2hex(random_bytes(24));
            }
        }

        return $values;
    }

    /**
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The users table.
     * @param string|null $column The column.
     * @return bool
     */
    private static function has(TableSchemaInterface $schema, ?string $column): bool
    {
        return $column !== null && $schema->hasColumn($column);
    }
}
