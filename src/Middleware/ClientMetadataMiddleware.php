<?php
declare(strict_types=1);

namespace Sso\Middleware;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sso\Service\ClientMetadata;
use Sso\SsoPlugin;

/**
 * Answers `GET /sso/client-metadata` itself, before the application's
 * authentication: the addresses the identity provider must register for this
 * application, so `new-id-app.sh` reads them instead of being told. Nothing in
 * it is secret; every address it names is reachable by anyone anyway.
 */
class ClientMetadataMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== SsoPlugin::CLIENT_METADATA_PATH) {
            return $handler->handle($request);
        }
        if ($request->getMethod() !== 'GET') {
            return (new Response())->withStatus(405)->withHeader('Allow', 'GET');
        }

        return (new Response())
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode(ClientMetadata::describe(), JSON_UNESCAPED_SLASHES));
    }
}
