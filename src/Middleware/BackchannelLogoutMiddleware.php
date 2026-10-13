<?php
declare(strict_types=1);

namespace Sso\Middleware;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sso\Service\BackchannelLogout;
use Sso\SsoPlugin;

/**
 * Answers `POST /sso/backchannel-logout` itself. The plugin places it before
 * the application's CSRF middleware: the logout token is a signed
 * server-to-server POST with no CSRF token, and its signature is what proves
 * it. Every other request passes through.
 */
class BackchannelLogoutMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!SsoPlugin::isCsrfExempt($request)) {
            return $handler->handle($request);
        }
        if ($request->getMethod() !== 'POST') {
            return (new Response())->withStatus(405)->withHeader('Allow', 'POST');
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || $body === []) {
            parse_str((string)$request->getBody(), $body);
        }
        $status = (new BackchannelLogout())->handle($body['logout_token'] ?? null);

        return (new Response())->withStatus($status)->withHeader('Cache-Control', 'no-store');
    }
}
