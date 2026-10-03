<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Sso\Model\Entity\IdentitySourceType;

/**
 * Turns a verified identity into the application's user row.
 *
 * The row is matched on `external_id`. A row not yet linked is matched on a
 * verified email address, which is how existing accounts move across at
 * cutover. Otherwise a new row is created. Every sign-in refreshes the email,
 * name and active flag from the provider, which is the master record.
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
        $fields = self::fields();

        $user = $users->find()->where([$fields['external_id'] => $subject])->first();
        if ($user === null && ($claims['email_verified'] ?? false) === true) {
            $user = $users->find()
                ->where([$fields['email'] => $email, $fields['external_id'] . ' IS' => null])
                ->first();
        }

        $isNew = $user === null;
        if ($isNew && !Configure::read('Sso.createUsers', true)) {
            throw new SsoException(sprintf('No user matches %s and creating users is switched off.', $email));
        }
        $user ??= $users->newEmptyEntity();

        $name = $claims['name'] ?? null;
        $values = [
            $fields['external_id'] => $subject,
            $fields['email'] => $email,
            $fields['identity_source_id'] => $this->fetchTable('Sso.IdentitySourceTypes')
                ->idForCode(IdentitySourceType::SSO),
            $fields['synced_at'] => DateTime::now(),
        ];
        if ($fields['name'] !== null) {
            $values[$fields['name']] = is_string($name) && $name !== '' ? $name : $email;
        }
        if ($fields['is_active'] !== null) {
            // The provider issues tokens only to active accounts.
            $values[$fields['is_active']] = true;
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

        // The application's validation describes its own forms (a password on
        // create, for one); this data comes from the provider. Its rules, such
        // as a unique email address, still apply.
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
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->users()->find()->where([self::fields()['external_id'] => $subject])->first();
    }

    /**
     * @return \Cake\ORM\Table
     */
    public function users(): Table
    {
        return $this->fetchTable((string)Configure::read('Sso.userModel', 'Users'));
    }

    /**
     * Column names on the users table, overridable through `Sso.fields`. A null
     * name or is_active column is not written.
     *
     * @return array{external_id: string, email: string, name: string|null, is_active: string|null, identity_source_id: string, synced_at: string}
     */
    public static function fields(): array
    {
        /** @var array{external_id: string, email: string, name: string|null, is_active: string|null, identity_source_id: string, synced_at: string} */
        return array_merge([
            'external_id' => 'external_id',
            'email' => 'email',
            'name' => 'name',
            'is_active' => 'is_active',
            'identity_source_id' => 'identity_source_id',
            'synced_at' => 'synced_at',
        ], (array)Configure::read('Sso.fields', []));
    }
}
