<?php
declare(strict_types=1);

namespace Sso\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\Routing\Router;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;
use Throwable;

/**
 * Signs out a session the account no longer honours, on its next request:
 *
 * - the user row is gone or deactivated (the identity provider disabled the
 *   account, or the application did);
 * - the session started before the identity's `sessions_valid_from`, which a
 *   back-channel logout moves forward.
 *
 * The plugin inserts it after the Authentication middleware, which provides
 * the identity. It judges only sessions the plugin established
 * (`SsoSession::isSsoSession()`): requests without an identity, an
 * application's own password sign-in and token-authenticated requests (whose
 * identity is not a user at all) pass through untouched, and stay the
 * application's to manage.
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
        if (
            $identity === null
            || !SsoSession::isSsoSession($request->getSession())
            || !$this->ended($request, $identity->getIdentifier())
        ) {
            return $handler->handle($request);
        }

        $authentication = $request->getAttribute('authentication');
        if (is_object($authentication) && method_exists($authentication, 'clearIdentity')) {
            $result = $authentication->clearIdentity($request, new Response());
            $request = $result['request'];
        }
        $request->getSession()->destroy();
        $request->getSession()->start();
        $request->getFlash()->info(__d('sso', 'Your session has ended. Please sign in again.'));

        return (new Response())
            ->withStatus(302)
            ->withLocation(Router::url(Configure::read('Sso.loginUrl'), true));
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

        $activeField = UserProvisioner::fields()['is_active'];
        if (
            $activeField !== null
            && $provisioner->users()->getSchema()->hasColumn($activeField)
            && !$user->get($activeField)
        ) {
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
        $establishedAt = $request->getSession()->read(SsoSession::ESTABLISHED_AT);

        // A session with no stamp cannot prove it is newer than the logout.
        return !is_int($establishedAt) || $establishedAt < $validFrom->getTimestamp();
    }
}
