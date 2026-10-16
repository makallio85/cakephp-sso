<?php
declare(strict_types=1);

namespace Sso\Controller;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Routing\Router;
use Sso\Service\ConnectionManager;
use Sso\Service\Provisioning;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;

/**
 * Settings › Identity provider: the connection to the identity provider.
 *
 *   GET  /sso/settings          — the form, and whether the server answers
 *   POST /sso/settings/update   — test, then save
 *   POST /sso/settings/test     — test the values in the form, save nothing
 *   POST /sso/settings/allow-provisioning — let Rock Software ID connect the
 *                                 application again for an hour
 *   POST /sso/settings/password-sign-in — turn the application's own password
 *                                 sign-in off or on (`Sso.passwordSignIn`)
 *
 * Who may open it is the application's decision: `Sso.settingsAccess`, a
 * callable receiving the identity and the request. Without one, any signed-in
 * user may, since signing in already required the application's group.
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class SettingsController extends SettingsBaseController
{
    public const PROVISIONING_WINDOW_MINUTES = 60;

    public const EVENT_PASSWORD_SIGN_IN_CHANGED = 'Sso.passwordSignInChanged';

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
            // The Authentication component sends the anonymous request to sign in.
            return;
        }
        $access = Configure::read('Sso.settingsAccess');
        $allowed = $access === null || (is_callable($access) && $access($identity, $this->getRequest()) === true);
        if (!$allowed) {
            throw new ForbiddenException(__d('sso', 'You may not change the identity provider settings.'));
        }
    }

    /**
     * @return void
     */
    public function index(): void
    {
        $manager = new ConnectionManager();
        $current = $manager->current();

        $this->renderForm($current === null ? [] : [
            'issuer_url' => $current->issuer_url,
            'client_id' => $current->client_id,
            'required_group' => $current->required_group,
        ], $current === null ? null : $manager->check([
            'issuer_url' => $current->issuer_url,
            'client_id' => $current->client_id,
        ]));
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function update(): ?Response
    {
        if (!$this->getRequest()->is('post')) {
            // The page stays at this address after a refused save; reloading it
            // or opening it arrives as a GET.
            return $this->redirect(['action' => 'index']);
        }
        $data = $this->submitted();

        $userId = (int)$this->Authentication->getIdentity()?->getIdentifier();
        $problem = (new ConnectionManager())->update($data, $userId);
        if ($problem === null) {
            $this->Flash->success(__d('sso', 'Saved. Sign-in now uses this identity server.'));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error(__d('sso', 'Not saved. {0} The previous settings are still in use.', $problem));
        $this->renderForm($data, $problem, true);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function test(): ?Response
    {
        if (!$this->getRequest()->is('post')) {
            return $this->redirect(['action' => 'index']);
        }
        $data = $this->submitted();

        $problem = (new ConnectionManager())->check($data);
        if ($problem === null) {
            $this->Flash->success(__d('sso', 'The identity server answered. Nothing was saved.'));
        } else {
            $this->Flash->error(__d('sso', 'The identity server did not answer: {0}', $problem));
        }
        $this->renderForm($data, $problem, $problem !== null);

        return null;
    }

    /**
     * Opens provisioning for an hour, for a new client secret or client. The
     * next provisioning closes it again.
     *
     * @return \Cake\Http\Response|null
     */
    public function allowProvisioning(): ?Response
    {
        if (!$this->getRequest()->is('post')) {
            return $this->redirect(['action' => 'index']);
        }
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        if ($settings->current() === null || Provisioning::config() === null) {
            return $this->redirect(['action' => 'index']);
        }

        $userId = (int)$this->Authentication->getIdentity()?->getIdentifier();
        $settings->getConnection()->transactional(function () use ($settings, $userId): void {
            $settings->openProvisioning(self::PROVISIONING_WINDOW_MINUTES);
            /** @var \Sso\Model\Table\SsoSettingChangesTable $changes */
            $changes = $this->fetchTable('Sso.SsoSettingChanges');
            $changes->saveOrFail($changes->newEntity(['user_id' => $userId]));
        });
        $this->Flash->success(__d('sso', 'Rock Software ID may connect this application during the next hour.'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Turns the application's own password sign-in off or on. Off only from a
     * session signed in through the identity provider (not an emergency
     * link), with the connection answering: whoever turns the password form
     * off has just proved the other way in works.
     *
     * @return \Cake\Http\Response|null
     */
    public function passwordSignIn(): ?Response
    {
        if (!$this->getRequest()->is('post') || Configure::read('Sso.passwordSignIn') !== true) {
            return $this->redirect(['action' => 'index']);
        }
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $current = $settings->enabled();
        if ($current === null) {
            return $this->redirect(['action' => 'index']);
        }
        $enable = (string)$this->getRequest()->getData('enabled') === '1';
        if (!$enable && !$this->canTurnPasswordSignInOff()) {
            $this->Flash->error(__d(
                'sso',
                'Password sign-in stays on. Sign in through Rock Software ID first, so nobody can lock themselves out.',
            ));

            return $this->redirect(['action' => 'index']);
        }
        if (!$enable) {
            $problem = (new ConnectionManager())->check([
                'issuer_url' => $current->issuer_url,
                'client_id' => $current->client_id,
            ]);
            if ($problem !== null) {
                $this->Flash->error(
                    __d('sso', 'Password sign-in stays on: the identity server does not answer. {0}', $problem),
                );

                return $this->redirect(['action' => 'index']);
            }
        }

        $identity = $this->Authentication->getIdentity();
        $userId = (int)$identity?->getIdentifier();
        $settings->getConnection()->transactional(function () use ($settings, $enable, $userId): void {
            $settings->setPasswordSignIn($enable, $userId);
            /** @var \Sso\Model\Table\SsoSettingChangesTable $changes */
            $changes = $this->fetchTable('Sso.SsoSettingChanges');
            $changes->saveOrFail($changes->newEntity(['user_id' => $userId]));
        });
        EventManager::instance()->dispatch(new Event(self::EVENT_PASSWORD_SIGN_IN_CHANGED, $this, [
            'user' => $identity?->getOriginalData(),
            'enabled' => $enable,
        ]));
        $this->Flash->success($enable
            ? __d('sso', 'Password sign-in is on. People can sign in with their password again.')
            : __d('sso', 'Password sign-in is off. Only Rock Software ID signs people in.'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * A user's name (else email) for "changed by" lines, or null when the
     * user is gone or unknown.
     *
     * @param int|null $userId The user.
     * @return string|null
     */
    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $provisioner = new UserProvisioner();
        $user = $provisioner->users()->find()->where(['id' => $userId])->first();
        $nameField = UserProvisioner::fields()['name'];
        if ($nameField === null || !$provisioner->users()->getSchema()->hasColumn($nameField)) {
            $nameField = UserProvisioner::fields()['email'];
        }

        return $user instanceof EntityInterface ? (string)$user->get($nameField) : null;
    }

    /**
     * Whether this session may turn password sign-in off: one signed in
     * through the identity provider itself.
     *
     * @return bool
     */
    private function canTurnPasswordSignInOff(): bool
    {
        $session = $this->getRequest()->getSession();

        return SsoSession::isSsoSession($session)
            && $session->read(SsoSession::METHOD) === SsoSession::METHOD_OIDC;
    }

    /**
     * @return array<string, string>
     */
    private function submitted(): array
    {
        $request = $this->getRequest();

        return [
            'issuer_url' => trim((string)$request->getData('issuer_url')),
            'client_id' => trim((string)$request->getData('client_id')),
            'client_secret' => (string)$request->getData('client_secret'),
            'required_group' => trim((string)$request->getData('required_group')),
        ];
    }

    /**
     * @param array<string, mixed> $values What the fields show. The secret never is.
     * @param string|null $problem Why the server cannot be used, or null.
     * @param bool $issuerInvalid Whether to mark the address field.
     * @return void
     */
    private function renderForm(array $values, ?string $problem, bool $issuerInvalid = false): void
    {
        unset($values['client_secret']);
        /** @var \Sso\Model\Table\SsoSettingChangesTable $changes */
        $changes = $this->fetchTable('Sso.SsoSettingChanges');
        $latest = $changes->latest();

        $changedBy = $latest === null ? null : $this->userName($latest->user_id);

        $this->set([
            'values' => $values,
            'configured' => (new ConnectionManager())->current() !== null,
            'problem' => $problem,
            'issuerInvalid' => $issuerInvalid,
            'callbackUrl' => Router::url(
                ['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'callback', 'prefix' => false],
                true,
            ),
            'lastChange' => $latest,
            'lastChangedBy' => $changedBy,
            'lastProvisioning' => $this->fetchTable('Sso.SsoProvisionings')->latest(),
            'provisioningEnabled' => Provisioning::config() !== null,
            'provisioningOpenUntil' => (new ConnectionManager())->current()?->provisioning_open_until,
            'passwordSignInOffered' => Configure::read('Sso.passwordSignIn') === true
                && (new ConnectionManager())->current()?->is_enabled === true,
            'passwordSignInUnsupported' => Configure::read('Sso.passwordSignIn') !== true
                && (new ConnectionManager())->current()?->is_enabled === true,
            'passwordSignInEnabled' => $this->fetchTable('Sso.SsoSettings')->passwordSignInAllowed(),
            'canTurnPasswordSignInOff' => $this->canTurnPasswordSignInOff(),
            'passwordSignInChangedBy' => $this->userName(
                (new ConnectionManager())->current()?->password_sign_in_changed_by,
            ),
            'passwordSignInChangedAt' => (new ConnectionManager())->current()?->password_sign_in_changed_at,
        ]);
        $this->render('index');
    }
}
