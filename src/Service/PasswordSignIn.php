<?php
declare(strict_types=1);

namespace Sso\Service;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Sso\Model\Entity\SsoSessionEventType;

/**
 * The sign-in page's email and password form, checked against the
 * application's own users table.
 *
 * Offered while the application declares passwords (`Sso.passwordSignIn`)
 * without keeping a form of its own (`Sso.passwordForm` is not `application`),
 * its users table has the password column (`Sso.fields.password`) and the
 * switch on the settings page is on. Hashes are CakePHP's default, so the
 * passwords an application already has keep working; an outdated hash is
 * replaced on the next sign-in.
 *
 * Refused attempts are counted from the sign-in history: after
 * `MAX_FAILURES_PER_EMAIL` for one address, or `MAX_FAILURES_PER_IP` from
 * one client, within `WINDOW_MINUTES`, the form refuses without checking.
 */
class PasswordSignIn
{
    use LocatorAwareTrait;

    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const WINDOW_MINUTES = 15;
    public const MIN_LENGTH = 12;

    /** A hash of a random string, checked when no user has the address. */
    private const TIMING_HASH = '$2y$12$r9c/8ZoIzvUUC6fGpt5ydeRKcU.JldqjnC2NZciZc3Am5PhBohx/u';

    /**
     * Whether the sign-in page offers the form now.
     *
     * @return bool
     */
    public function available(): bool
    {
        if (Configure::read('Sso.passwordSignIn') !== true || Configure::read('Sso.passwordForm') === 'application') {
            return false;
        }
        $column = UserProvisioner::fields()['password'];
        if ($column === null || !(new UserProvisioner())->users()->getSchema()->hasColumn($column)) {
            return false;
        }
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');

        return $settings->passwordSignInAllowed();
    }

    /**
     * Checks an email address and password.
     *
     * @param string $email The address typed.
     * @param string $password The password typed.
     * @param string|null $ip The client's address.
     * @return array{result: string, user: \Cake\Datasource\EntityInterface|null}
     *   `result` is `SsoSessionEventType::SIGNED_IN` or the refusal.
     */
    public function attempt(string $email, string $password, ?string $ip): array
    {
        $email = trim($email);
        if (!$this->available()) {
            return ['result' => SsoSessionEventType::PASSWORD_SIGN_IN_OFF, 'user' => null];
        }
        /** @var \Sso\Model\Table\SsoSessionEventsTable $events */
        $events = $this->fetchTable('Sso.SsoSessionEvents');
        $failures = $events->recentPasswordFailures($email, $ip, self::WINDOW_MINUTES);
        if ($failures['email'] >= self::MAX_FAILURES_PER_EMAIL || $failures['ip'] >= self::MAX_FAILURES_PER_IP) {
            return ['result' => SsoSessionEventType::THROTTLED, 'user' => $this->findUser($email)];
        }

        $hasher = new DefaultPasswordHasher();
        $user = $email === '' ? null : $this->findUser($email);
        $column = (string)UserProvisioner::fields()['password'];
        if ($user === null) {
            // The same work as a real check, so the answer's timing does not
            // tell which addresses have an account.
            $hasher->check($password, self::TIMING_HASH);

            return ['result' => SsoSessionEventType::UNKNOWN_EMAIL, 'user' => null];
        }
        $hash = (string)$user->get($column);
        if ($password === '' || !$hasher->check($password, $hash)) {
            return ['result' => SsoSessionEventType::WRONG_PASSWORD, 'user' => $user];
        }
        if (!UserProvisioner::isActive($user)) {
            return ['result' => SsoSessionEventType::USER_INACTIVE, 'user' => $user];
        }
        if ($hasher->needsRehash($hash)) {
            $user->set($column, $hasher->hash($password), ['guard' => false]);
            (new UserProvisioner())->users()->saveOrFail($user, ['validate' => false]);
        }

        return ['result' => SsoSessionEventType::SIGNED_IN, 'user' => $user];
    }

    /**
     * Sets a user's password (`bin/cake sso set_password`).
     *
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $password The new password, at least `MIN_LENGTH` characters.
     * @return void
     */
    public function setPassword(EntityInterface $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new SsoException(sprintf('The password must be at least %d characters.', self::MIN_LENGTH));
        }
        $column = UserProvisioner::fields()['password'];
        $users = (new UserProvisioner())->users();
        if ($column === null || !$users->getSchema()->hasColumn($column)) {
            throw new SsoException('The users table has no password column.');
        }
        $user->set($column, (new DefaultPasswordHasher())->hash($password), ['guard' => false]);
        $users->saveOrFail($user, ['validate' => false]);
    }

    /**
     * The user with this address, compared without regard to case.
     *
     * @param string $email The address.
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findUser(string $email): ?EntityInterface
    {
        $users = (new UserProvisioner())->users();
        $field = $users->aliasField(UserProvisioner::fields()['email']);

        /** @var \Cake\Datasource\EntityInterface|null */
        return $users->find()
            ->where(fn(QueryExpression $exp, SelectQuery $query) => $exp->eq(
                $query->func()->lower([$field => 'identifier']),
                mb_strtolower(trim($email)),
            ))
            ->first();
    }
}
