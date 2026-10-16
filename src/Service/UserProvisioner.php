<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Core\Configure;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Sso\Model\Entity\IdentitySourceType;
use Sso\Model\Table\SsoIdentitiesTable;

/**
 * Turns a verified identity into the application's user row.
 *
 * The link lives in the plugin's `sso_identities` table, so the application's
 * `users` table needs nothing of its own: the plugin writes only the columns it
 * finds (email, name, active flag; names in `Sso.fields`).
 *
 * The user is found through their identity's `external_id`. A user not yet
 * linked is matched on a verified email address, which is how existing accounts
 * move across at cutover. Otherwise a new user is created. Every sign-in
 * refreshes the email, name and active flag from the provider, which is the
 * master record.
 *
 * Fields the application needs beyond these are filled by a listener on
 * `Sso.beforeUserSave`.
 */
class UserProvisioner
{
    use LocatorAwareTrait;

    public const EVENT_BEFORE_USER_SAVE = 'Sso.beforeUserSave';

    /**
     * @param array<string, mixed> $claims Verified ID token claims.
     * @return \Cake\Datasource\EntityInterface
     */
    public function provision(array $claims): EntityInterface
    {
        $subject = (string)$claims['sub'];
        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new SsoException('The identity carries no email address.');
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

        $isNew = $user === null;
        if ($isNew && !Configure::read('Sso.createUsers')) {
            throw new SsoException(sprintf('No user matches %s and creating users is switched off.', $email));
        }
        $user ??= $users->newEmptyEntity();

        $name = $claims['name'] ?? null;
        $values = [$fields['email'] => $email];
        if (self::has($schema, $fields['name'])) {
            $values[(string)$fields['name']] = is_string($name) && $name !== '' ? $name : $email;
        }
        if (self::has($schema, $fields['is_active'])) {
            // The provider issues tokens only to active accounts.
            $values[(string)$fields['is_active']] = true;
        }
        if ($isNew) {
            $values += $this->requiredDefaults($schema);
        }
        $values += $this->legacyColumns($schema, $subject);
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
     * sign-in would (the application's `Sso.beforeUserSave` listeners run, a
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
        return $this->fetchTable((string)Configure::read('Sso.userModel', 'Users'));
    }

    /**
     * @return \Sso\Model\Table\SsoIdentitiesTable
     */
    public function identities(): SsoIdentitiesTable
    {
        /** @var \Sso\Model\Table\SsoIdentitiesTable */
        return $this->fetchTable('Sso.SsoIdentities');
    }

    /**
     * Column names on the users table, overridable through `Sso.fields`. A null
     * or missing column is not written.
     *
     * @return array{email: string, name: string|null, is_active: string|null}
     */
    public static function fields(): array
    {
        /** @var array{email: string, name: string|null, is_active: string|null} */
        return array_merge([
            'email' => 'email',
            'name' => 'name',
            'is_active' => 'is_active',
        ], (array)Configure::read('Sso.fields', []));
    }

    /**
     * A value for every column a new row cannot be inserted without, other than
     * the ones written above: a `password` gets an unusable value, which no
     * password check ever matches. Anything else is the application's to fill,
     * on `Sso.beforeUserSave`.
     *
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The users table.
     * @return array<string, mixed>
     */
    private function requiredDefaults(TableSchemaInterface $schema): array
    {
        $values = [];
        foreach (['password'] as $column) {
            $definition = $schema->getColumn($column);
            if ($definition !== null && !$definition['null'] && $definition['default'] === null) {
                $values[$column] = '!sso:' . bin2hex(random_bytes(24));
            }
        }

        return $values;
    }

    /**
     * Columns a 1.1 application kept on its users table, written while they
     * still exist so it can drop them at its own pace.
     *
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The users table.
     * @param string $subject The subject identifier.
     * @return array<string, mixed>
     */
    private function legacyColumns(TableSchemaInterface $schema, string $subject): array
    {
        $values = [];
        if ($schema->hasColumn('external_id')) {
            $values['external_id'] = $subject;
        }
        if ($schema->hasColumn('synced_at')) {
            $values['synced_at'] = DateTime::now();
        }
        if ($schema->hasColumn('identity_source_id')) {
            $values['identity_source_id'] = $this->fetchTable('Sso.IdentitySourceTypes')
                ->idFor(IdentitySourceType::SSO);
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
