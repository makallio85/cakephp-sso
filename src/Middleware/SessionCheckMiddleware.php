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
 * - the session started before the user's `Sso.sessionsValidFromField`, which a
 *   back-channel logout moves forward.
 *
 * Add it after the Authentication middleware, which provides the identity.
 * Requests without an identity pass through untouched, so the sign-in routes
 * and the back-channel logout are unaffected.
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
        if ($identity === null || !$this->ended($request, $identity->getIdentifier())) {
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
            ->withLocation(Router::url(Configure::read('Sso.loginUrl', '/users/login'), true));
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
        if ($activeField !== null && !$user->get($activeField)) {
            return true;
        }

        $validFromField = Configure::read('Sso.sessionsValidFromField');
        $validFrom = is_string($validFromField) ? $user->get($validFromField) : null;
        if (!$validFrom instanceof DateTimeInterface) {
            return false;
        }
        $establishedAt = $request->getSession()->read(SsoSession::ESTABLISHED_AT);

        // A session with no stamp cannot prove it is newer than the logout.
        return !is_int($establishedAt) || $establishedAt < $validFrom->getTimestamp();
    }
}
