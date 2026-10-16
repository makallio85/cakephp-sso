<?php
declare(strict_types=1);

namespace Sso\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Service\PasswordSignIn;
use Sso\Service\SignInHistory;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;
use Throwable;

/**
 * Signs out a session the account no longer honours, on its next request.
 *
 * A session the plugin established (`SsoSession::isPluginSession()`) ends when:
 *
 * - the user row is gone or deactivated in the application;
 * - the session started before the identity's `sessions_valid_from`, which a
 *   back-channel logout moves forward, or before an administrator ended the
 *   user's sessions on the users page;
 * - it is a password session and password sign-in has been turned off;
 * - it is older than the maximum session length, or idle longer than the idle
 *   timeout, set on the settings page (both off by default).
 *
 * Any other session is the application's to manage, with one exception: in an
 * application that declared a password form (`Sso.passwordSignIn`), a password
 * session ends once password sign-in has been turned off on the settings
 * page. Requests without an identity, and token-authenticated requests (no
 * identity in the session), always pass through untouched.
 *
 * The plugin inserts it after the Authentication middleware, which provides
 * the identity.
 */
class SessionCheckMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request instanceof ServerRequest) {
            return $handler->handle($request);
        }
        /** @var \Authentication\IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $message = null;
        $session = $request->getSession();
        if ($identity !== null && SsoSession::isPluginSession($session)) {
            if ($this->ended($request, $identity->getIdentifier())) {
                $message = __d('sso', 'Your session has ended. Please sign in again.');
            } elseif ($this->passwordSessionOff($request)) {
                $message = __d('sso', 'Password sign-in has been turned off. Please sign in with Rock Software ID.');
            } elseif ($this->expired($request)) {
                (new SignInHistory())->record(SsoSessionEventType::SESSION_EXPIRED, [
                    'user_id' => (int)$identity->getIdentifier(),
                    'method' => (string)$session->read(SsoSession::METHOD),
                ], $request);
                $message = __d('sso', 'Your session has expired. Please sign in again.');
            } else {
                $session->write(SsoSession::LAST_SEEN_AT, time());
            }
        } elseif ($identity !== null && $this->passwordSessionBarred($request)) {
            $message = __d('sso', 'Password sign-in has been turned off. Please sign in with Rock Software ID.');
        }
        if ($message === null) {
            return $handler->handle($request);
        }

        $authentication = $request->getAttribute('authentication');
        if (is_object($authentication) && method_exists($authentication, 'clearIdentity')) {
            $result = $authentication->clearIdentity($request, new Response());
            $request = $result['request'];
        }
        $request->getSession()->destroy();
        $request->getSession()->start();
        $request->getFlash()->info($message);

        return (new Response())
            ->withStatus(302)
            ->withLocation(Router::url(Configure::read('Sso.loginUrl'), true));
    }

    /**
     * Whether this is a password session in an application whose password
     * sign-in has been turned off: the application declared a password form
     * (`Sso.passwordSignIn`), the session holds an identity the plugin did not
     * establish, and the switch on the settings page is off. A token request
     * carries no session identity and is never touched.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @return bool
     */
    private function passwordSessionBarred(ServerRequest $request): bool
    {
        if (Configure::read('Sso.passwordSignIn') !== true || !$request->getSession()->check('Auth')) {
            return false;
        }
        try {
            /** @var \Sso\Model\Table\SsoSettingsTable $settings */
            $settings = TableRegistry::getTableLocator()->get('Sso.SsoSettings');

            return $settings->enabled() !== null && !$settings->passwordSignInAllowed();
        } catch (Throwable $e) {
            // A database hiccup is not evidence the switch is off.
            Log::warning('SSO password session check skipped: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Whether this plugin password session outlived password sign-in.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @return bool
     */
    private function passwordSessionOff(ServerRequest $request): bool
    {
        if ($request->getSession()->read(SsoSession::METHOD) !== SsoSession::METHOD_PASSWORD) {
            return false;
        }
        try {
            return !(new PasswordSignIn())->available();
        } catch (Throwable $e) {
            Log::warning('SSO password session check skipped: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Whether the session is past the maximum length or the idle timeout.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @return bool
     */
    private function expired(ServerRequest $request): bool
    {
        try {
            /** @var \Sso\Model\Table\SsoSettingsTable $settings */
            $settings = TableRegistry::getTableLocator()->get('Sso.SsoSettings');
            $limits = $settings->sessionLimits();
        } catch (Throwable $e) {
            Log::warning('SSO session limits not checked: ' . $e->getMessage());

            return false;
        }
        $session = $request->getSession();
        $now = time();
        $establishedAt = $session->read(SsoSession::ESTABLISHED_AT);
        if ($limits['max_hours'] > 0 && is_int($establishedAt) && $now - $establishedAt > $limits['max_hours'] * 3600) {
            return true;
        }
        $lastSeenAt = $session->read(SsoSession::LAST_SEEN_AT);

        return $limits['idle_minutes'] > 0 && is_int($lastSeenAt) && $now - $lastSeenAt > $limits['idle_minutes'] * 60;
    }

    /**
     * @param \Cake\Http\ServerRequest $request The request.
     * @param mixed $userId The identity's identifier.
     * @return bool
     */
    private function ended(ServerRequest $request, mixed $userId): bool
    {
        $provisioner = new UserProvisioner();
        try {
            $user = $provisioner->users()->find()->where(['id' => $userId])->first();
        } catch (Throwable $e) {
            // A database hiccup is not evidence the session has ended.
            Log::warning('SSO session check skipped: ' . $e->getMessage());

            return false;
        }
        if (!$user instanceof EntityInterface) {
            return true;
        }

        if (!UserProvisioner::isActive($user)) {
            return true;
        }
        $establishedAt = $request->getSession()->read(SsoSession::ESTABLISHED_AT);
        /** @var \Sso\Model\Table\SsoSessionEventsTable $events */
        $events = TableRegistry::getTableLocator()->get('Sso.SsoSessionEvents');
        $endedAt = $events->sessionsEndedAt((int)$user->get('id'));
        if ($endedAt !== null && (!is_int($establishedAt) || $establishedAt < $endedAt->getTimestamp())) {
            return true;
        }

        $identity = $provisioner->identities()->findForUser((int)$user->get('id'));
        $validFrom = $identity?->sessions_valid_from;
        $legacyField = Configure::read('Sso.sessionsValidFromField');
        $hasLegacyField = is_string($legacyField) && $provisioner->users()->getSchema()->hasColumn($legacyField);
        if ($validFrom === null && $hasLegacyField) {
            $validFrom = $user->get($legacyField);
        }
        if (!$validFrom instanceof DateTimeInterface) {
            return false;
        }
        // A session with no stamp cannot prove it is newer than the logout.
        return !is_int($establishedAt) || $establishedAt < $validFrom->getTimestamp();
    }
}
