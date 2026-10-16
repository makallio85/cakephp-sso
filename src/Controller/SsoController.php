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
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Model\Entity\SsoSetting;
use Sso\Service\BackchannelLogout;
use Sso\Service\ClientMetadata;
use Sso\Service\OidcClient;
use Sso\Service\PasswordSignIn;
use Sso\Service\Provisioning;
use Sso\Service\SignInHistory;
use Sso\Service\SsoException;
use Sso\Service\UserInactiveException;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;

/**
 * Sign-in through the identity provider or with a password, sign-out,
 * back-channel logout and emergency sign-in.
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
            [
                'index', 'login', 'password', 'callback', 'logout', 'backchannelLogout', 'emergency', 'provision',
                'clientMetadata',
            ],
        );

        $layout = Configure::read('Sso.layout');
        if (is_string($layout)) {
            $this->viewBuilder()->setLayout($layout);
        }
    }

    /**
     * The sign-in page: "Sign in with Rock Software ID", and the email and
     * password form while password sign-in is on. Failed and refused sign-ins,
     * and signing out, come back here with their reason as a flash message. An
     * application gives it its look by overriding
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
            'passwordSignIn' => (new PasswordSignIn())->available(),
            'redirect' => $redirect,
            'email' => (string)$this->getRequest()->getSession()->consume('Sso.passwordEmail'),
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
     * The sign-in page's email and password form.
     *
     * @return \Cake\Http\Response|null
     */
    public function password(): ?Response
    {
        $request = $this->getRequest();
        $redirect = $this->safeRedirect($request->getData('redirect'));
        $back = ['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'index', 'prefix' => false,
            '?' => array_filter(['redirect' => $redirect])];
        if (!$request->is('post')) {
            return $this->redirect($back);
        }
        $email = trim((string)$request->getData('email'));
        $ip = SignInHistory::clientIp($request);
        $attempt = (new PasswordSignIn())->attempt($email, (string)$request->getData('password'), $ip);
        $user = $attempt['user'];

        if ($attempt['result'] === SsoSessionEventType::SIGNED_IN && $user !== null) {
            $this->signIn($user, SsoSession::METHOD_PASSWORD, [], null);

            return $this->redirect($redirect ?? Configure::read('Sso.loginRedirect'));
        }

        (new SignInHistory())->record($attempt['result'], [
            'user_id' => $user === null ? null : (int)$user->get('id'),
            'email' => $email,
            'method' => SsoSession::METHOD_PASSWORD,
        ], $request);
        $this->Flash->error(match ($attempt['result']) {
            SsoSessionEventType::THROTTLED => __d(
                'sso',
                'Too many failed sign-ins. Try again in {0} minutes, or sign in with Rock Software ID.',
                PasswordSignIn::WINDOW_MINUTES,
            ),
            SsoSessionEventType::PASSWORD_SIGN_IN_OFF => __d(
                'sso',
                'Password sign-in is off. Sign in with Rock Software ID.',
            ),
            SsoSessionEventType::USER_INACTIVE => __d('sso', 'Your account has been deactivated in this application.'),
            default => __d('sso', 'The email address or password is wrong.'),
        });
        $request->getSession()->write('Sso.passwordEmail', mb_substr($email, 0, 190));

        return $this->redirect($back);
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
                $known = is_string($claims['sub'] ?? null)
                    ? (new UserProvisioner())->findBySubject((string)$claims['sub'])
                    : null;
                (new SignInHistory())->record(SsoSessionEventType::NOT_IN_GROUP, [
                    'user_id' => $known === null ? null : (int)$known->get('id'),
                    'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                    'method' => SsoSession::METHOD_OIDC,
                ], $this->getRequest());
                $this->Flash->error(__d('sso', 'Your account has no access to this application.'));

                return $this->redirect($this->loginUrl());
            }

            $user = (new UserProvisioner())->provision($claims);
        } catch (UserInactiveException $e) {
            $known = (new PasswordSignIn())->findUser((string)($claims['email'] ?? ''));
            (new SignInHistory())->record(SsoSessionEventType::USER_INACTIVE, [
                'user_id' => $known === null ? null : (int)$known->get('id'),
                'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                'method' => SsoSession::METHOD_OIDC,
            ], $this->getRequest());
            Log::warning('SSO sign-in refused: ' . $e->getMessage());
            $this->Flash->error(__d('sso', 'Your account has been deactivated in this application.'));

            return $this->redirect($this->loginUrl());
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
            (new SignInHistory())->record(SsoSessionEventType::SIGNED_OUT, [
                'user_id' => (int)$user->get('id'),
                'method' => is_string($session->read(SsoSession::METHOD)) ? $session->read(SsoSession::METHOD) : null,
            ], $this->getRequest());
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
     * The addresses the provider must register. ClientMetadataMiddleware
     * answers it before authentication; this action serves an application
     * whose queue lacks it.
     *
     * @return \Cake\Http\Response
     */
    public function clientMetadata(): Response
    {
        return $this->getResponse()
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode(ClientMetadata::describe(), JSON_UNESCAPED_SLASHES));
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
     * @param array<string, mixed> $claims Verified claims, empty for password and emergency sign-in.
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
            SsoSession::LAST_SEEN_AT => time(),
            SsoSession::APPS => self::apps($claims['apps'] ?? null),
        ]);
        (new SignInHistory())->record(SsoSessionEventType::SIGNED_IN, [
            'user_id' => (int)$user->get('id'),
            'email' => (string)$user->get(UserProvisioner::fields()['email']),
            'method' => $method,
        ], $this->getRequest());

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
     * The applications the person may open, from the provider's `apps` claim:
     * names and https addresses only, at most 50.
     *
     * @param mixed $claim The claim.
     * @return array<array{name: string, url: string}>
     */
    public static function apps(mixed $claim): array
    {
        if (!is_array($claim)) {
            return [];
        }
        $apps = [];
        foreach ($claim as $app) {
            if (
                is_array($app)
                && is_string($app['name'] ?? null) && $app['name'] !== ''
                && is_string($app['url'] ?? null) && str_starts_with($app['url'], 'https://')
            ) {
                $apps[] = ['name' => mb_substr($app['name'], 0, 100), 'url' => mb_substr($app['url'], 0, 500)];
            }
        }

        return array_slice($apps, 0, 50);
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
        (new SignInHistory())->record(
            SsoSessionEventType::SIGN_IN_FAILED,
            ['method' => SsoSession::METHOD_OIDC],
            $this->getRequest(),
        );
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
