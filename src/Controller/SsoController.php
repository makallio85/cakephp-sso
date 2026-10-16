<?php
declare(strict_types=1);

namespace Sso\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Routing\Router;
use Sso\Model\Entity\SsoSetting;
use Sso\Service\BackchannelLogout;
use Sso\Service\OidcClient;
use Sso\Service\Provisioning;
use Sso\Service\SsoException;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;

/**
 * Sign-in through the identity provider, sign-out, back-channel logout and
 * emergency sign-in.
 *
 * The application's own controllers are not involved. The application learns
 * of each sign-in and sign-out through events:
 *
 * - `Sso.afterLogin` — data: user, claims, method. Record last login, audit,
 *   session freshness.
 * - `Sso.beforeLogout` — data: user.
 * - `Sso.backchannelLogout` — data: user. The provider ended the person's
 *   session or disabled the account.
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class SsoController extends Controller
{
    public const EVENT_AFTER_LOGIN = 'Sso.afterLogin';
    public const EVENT_BEFORE_LOGOUT = 'Sso.beforeLogout';
    public const EVENT_BACKCHANNEL_LOGOUT = BackchannelLogout::EVENT;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
        $this->Authentication->allowUnauthenticated(
            ['index', 'login', 'callback', 'logout', 'backchannelLogout', 'emergency'],
        );

        $layout = Configure::read('Sso.layout');
        if (is_string($layout)) {
            $this->viewBuilder()->setLayout($layout);
        }
    }

    /**
     * The sign-in page: one button, "Sign in with Rock Software ID". Failed and
     * refused sign-ins, and signing out, come back here with their reason as a
     * flash message. An application gives it its look by overriding
     * `templates/plugin/Sso/Sso/index.php`.
     *
     * @return \Cake\Http\Response|null
     */
    public function index(): ?Response
    {
        if ($this->Authentication->getIdentity() !== null) {
            return $this->redirect(Configure::read('Sso.loginRedirect'));
        }

        $redirect = $this->safeRedirect($this->getRequest()->getQuery('redirect'));
        $this->set([
            'ssoEnabled' => $this->fetchTable('Sso.SsoSettings')->enabled() !== null,
            'signInUrl' => [
                'plugin' => 'Sso',
                'controller' => 'Sso',
                'action' => 'login',
                'prefix' => false,
                '?' => array_filter(['redirect' => $redirect]),
            ],
        ]);

        return null;
    }

    /**
     * Starts the authorization code flow.
     *
     * @return \Cake\Http\Response|null
     */
    public function login(): ?Response
    {
        $setting = $this->fetchTable('Sso.SsoSettings')->enabled();
        if ($setting === null) {
            $this->Flash->error(__d('sso', 'Sign-in through Rock Software ID is not available.'));

            return $this->redirect($this->loginUrl());
        }

        $flow = [
            'state' => OidcClient::randomToken(),
            'nonce' => OidcClient::randomToken(),
            'verifier' => OidcClient::randomToken(),
            'redirect' => $this->safeRedirect($this->getRequest()->getQuery('redirect')),
        ];
        $this->getRequest()->getSession()->write(SsoSession::FLOW, $flow);

        try {
            $url = $this->client($setting)
                ->authorizationUrl($this->callbackUrl(), $flow['state'], $flow['nonce'], $flow['verifier']);
        } catch (SsoException $e) {
            return $this->fail($e);
        }

        return $this->redirect($url);
    }

    /**
     * Completes the flow: verifies the response, provisions the user and signs
     * them in.
     *
     * @return \Cake\Http\Response|null
     */
    public function callback(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $flow = $session->consume(SsoSession::FLOW);
        $setting = $this->fetchTable('Sso.SsoSettings')->enabled();

        try {
            if ($setting === null) {
                throw new SsoException('Sign-in through the identity provider is switched off.');
            }
            if (!is_array($flow)) {
                throw new SsoException('No sign-in is in progress in this browser session.');
            }
            $state = $this->getRequest()->getQuery('state');
            if (!is_string($state) || !hash_equals((string)$flow['state'], $state)) {
                throw new SsoException('The state parameter does not match.');
            }
            $error = $this->getRequest()->getQuery('error');
            if (is_string($error)) {
                throw new SsoException(sprintf('The identity provider returned %s.', $error));
            }
            $code = $this->getRequest()->getQuery('code');
            if (!is_string($code) || $code === '') {
                throw new SsoException('The callback carries no authorization code.');
            }

            $client = $this->client($setting);
            $tokens = $client->exchangeCode($code, $this->callbackUrl(), (string)$flow['verifier']);
            $claims = $client->validateIdToken((string)$tokens['id_token'], (string)$flow['nonce']);

            $group = $setting->required_group;
            if ($group !== null && $group !== '' && !in_array($group, (array)($claims['groups'] ?? []), true)) {
                // The groups the token did carry, so a misspelt group name shows.
                Log::warning(sprintf(
                    'SSO sign-in refused: %s is not in group %s (token groups: %s).',
                    $claims['email'] ?? $claims['sub'],
                    $group,
                    json_encode(array_values((array)($claims['groups'] ?? []))),
                ));
                $this->Flash->error(__d('sso', 'Your account has no access to this application.'));

                return $this->redirect($this->loginUrl());
            }

            $user = (new UserProvisioner())->provision($claims);
        } catch (SsoException $e) {
            return $this->fail($e);
        } catch (PersistenceFailedException $e) {
            // Typically the email address belongs to a row linked to another
            // identity, which the application's own rules refuse.
            return $this->fail(new SsoException(
                'The user row could not be saved: ' . json_encode($e->getEntity()->getErrors()),
                0,
                $e,
            ));
        }

        $this->signIn($user, SsoSession::METHOD_OIDC, $claims, (string)$tokens['id_token']);

        return $this->redirect($flow['redirect'] ?? Configure::read('Sso.loginRedirect'));
    }

    /**
     * Signs out here and at the identity provider.
     *
     * @return \Cake\Http\Response|null
     */
    public function logout(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $idToken = $session->read(SsoSession::ID_TOKEN);
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if ($user instanceof EntityInterface) {
            $this->notify(self::EVENT_BEFORE_LOGOUT, ['user' => $user]);
        }

        $this->Authentication->logout();
        $session->destroy();

        $afterLogout = Router::url($this->loginUrl(), true);
        $setting = $this->fetchTable('Sso.SsoSettings')->enabled();
        if ($setting !== null && is_string($idToken)) {
            try {
                $url = $this->client($setting)->endSessionUrl($idToken, $afterLogout);
                if ($url !== null) {
                    return $this->redirect($url);
                }
            } catch (SsoException $e) {
                Log::warning('SSO end-session URL unavailable: ' . $e->getMessage());
            }
        }

        return $this->redirect($afterLogout);
    }

    /**
     * Receives the provider's back-channel logout token.
     *
     * @return \Cake\Http\Response
     */
    public function backchannelLogout(): Response
    {
        // 1.2 answers this before the CSRF middleware
        // (BackchannelLogoutMiddleware); this action serves a 1.1 application
        // that exempts the path itself.
        $this->getRequest()->allowMethod(['post']);
        $status = (new BackchannelLogout())->handle($this->getRequest()->getData('logout_token'));

        return $this->getResponse()->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    /**
     * Receives the connection the provider hands over. ProvisionMiddleware
     * answers it before CSRF and sign-in; the route exists so routing lets the
     * request reach that middleware, and this action serves an application
     * whose queue lacks it.
     *
     * @return \Cake\Http\Response
     */
    public function provision(): Response
    {
        if (Provisioning::config() === null) {
            return $this->getResponse()->withStatus(404);
        }
        $this->getRequest()->allowMethod(['post']);
        $request = $this->getRequest();
        $status = (new Provisioning())->handle(
            $request->getHeaderLine('Authorization'),
            (string)$request->getBody(),
            $request->clientIp(),
        );

        return $this->getResponse()->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    /**
     * A single-use link from `bin/cake sso emergency_login`. GET shows a
     * confirmation, so a link preview cannot spend the link; POST signs in.
     *
     * @param string $token The raw token.
     * @return \Cake\Http\Response|null
     */
    public function emergency(string $token): ?Response
    {
        $logins = $this->fetchTable('Sso.SsoEmergencyLogins');
        $login = $logins->findUsable($token);
        if ($login === null) {
            $this->Flash->error(__d('sso', 'This sign-in link is invalid, expired or already used.'));

            return $this->redirect($this->loginUrl());
        }

        if (!$this->getRequest()->is('post')) {
            return null;
        }

        $provisioner = new UserProvisioner();
        $user = $provisioner->users()->find()->where(['id' => $login->user_id])->first();
        $activeField = UserProvisioner::fields()['is_active'];
        if (
            !$user instanceof EntityInterface
            || (
                $activeField !== null
                && $provisioner->users()->getSchema()->hasColumn($activeField)
                && !$user->get($activeField)
            )
            || !$logins->consume($login)
        ) {
            $this->Flash->error(__d('sso', 'This sign-in link is invalid, expired or already used.'));

            return $this->redirect($this->loginUrl());
        }

        Log::warning(sprintf('SSO emergency sign-in used for user %d.', $login->user_id));
        $this->signIn($user, SsoSession::METHOD_EMERGENCY, [], null);

        return $this->redirect(Configure::read('Sso.loginRedirect'));
    }

    /**
     * @param \Cake\Datasource\EntityInterface $user The user.
     * @param string $method One of the `SsoSession::METHOD_*` values.
     * @param array<string, mixed> $claims Verified claims, empty for emergency sign-in.
     * @param string|null $idToken The ID token, kept for ending the provider session.
     * @return void
     */
    private function signIn(EntityInterface $user, string $method, array $claims, ?string $idToken): void
    {
        $session = $this->getRequest()->getSession();
        // A new session id on every privilege change defeats session fixation.
        $session->renew();
        $this->Authentication->setIdentity($user);
        $session->write([
            SsoSession::AUTHENTICATED => true,
            SsoSession::METHOD => $method,
            SsoSession::ID_TOKEN => $idToken,
            SsoSession::ESTABLISHED_AT => time(),
        ]);

        $provisioner = new UserProvisioner();
        $identity = $provisioner->identities()->findForUser((int)$user->get('id'));
        if ($identity !== null) {
            $identity->last_login_at = DateTime::now();
            $provisioner->identities()->saveOrFail($identity);
        }
        // A 1.1 application may still read its own column.
        $lastLoginField = Configure::read('Sso.lastLoginField');
        if (is_string($lastLoginField) && $provisioner->users()->getSchema()->hasColumn($lastLoginField)) {
            $user->set($lastLoginField, DateTime::now(), ['guard' => false]);
            $provisioner->users()->saveOrFail($user, ['validate' => false]);
        }

        $this->notify(self::EVENT_AFTER_LOGIN, [
            'user' => $user,
            'claims' => $claims,
            'method' => $method,
        ]);
    }

    /**
     * @param \Sso\Model\Entity\SsoSetting $setting The connection.
     * @return \Sso\Service\OidcClient
     */
    private function client(SsoSetting $setting): OidcClient
    {
        return new OidcClient($setting, $this->fetchTable('Sso.SsoSettings')->clientSecret($setting));
    }

    /**
     * @param \Sso\Service\SsoException $e The failure.
     * @return \Cake\Http\Response|null
     */
    private function fail(SsoException $e): ?Response
    {
        Log::error('SSO sign-in failed: ' . $e->getMessage());
        $this->Flash->error(
            __d('sso', 'Sign-in through Rock Software ID failed. Try again, or contact the administrator.'),
        );

        return $this->redirect($this->loginUrl());
    }

    /**
     * @return string
     */
    private function callbackUrl(): string
    {
        return Router::url(['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'callback', 'prefix' => false], true);
    }

    /**
     * @return array<string, mixed>|string
     */
    private function loginUrl(): array|string
    {
        /** @var array<string, mixed>|string */
        return Configure::read('Sso.loginUrl');
    }

    /**
     * A local path to return to, or null. Absolute and protocol-relative URLs
     * are refused so the parameter cannot become an open redirect.
     *
     * @param mixed $redirect The `redirect` query parameter.
     * @return string|null
     */
    private function safeRedirect(mixed $redirect): ?string
    {
        if (!is_string($redirect) || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return null;
        }
        if (str_contains($redirect, '\\')) {
            return null;
        }

        return $redirect;
    }

    /**
     * @param string $name Event name.
     * @param array<string, mixed> $data Event data.
     * @return void
     */
    private function notify(string $name, array $data): void
    {
        EventManager::instance()->dispatch(new Event($name, $this, $data));
    }
}
