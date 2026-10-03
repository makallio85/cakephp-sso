<?php
declare(strict_types=1);

namespace TestApp;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Sso\SsoPlugin;

/**
 * A minimal consuming application: session authentication, CSRF protection
 * with the plugin's exemption, and the plugin's routes.
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        $this->addPlugin(SsoPlugin::class);
        $this->addPlugin('Authentication');
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $csrf = new CsrfProtectionMiddleware();
        $csrf->skipCheckCallback(fn(ServerRequestInterface $request) => SsoPlugin::isCsrfExempt($request));

        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add($csrf)
            ->add(new AuthenticationMiddleware($this));
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->connect('/users/login', ['controller' => 'Users', 'action' => 'login']);
        $routes->connect('/dashboard', ['controller' => 'Dashboard', 'action' => 'index']);
        parent::routes($routes);
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService(['unauthenticatedRedirect' => '/users/login']);
        $service->loadAuthenticator('Authentication.Session');

        return $service;
    }
}
