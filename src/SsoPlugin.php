<?php
declare(strict_types=1);

namespace Sso;

use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Sso\Command\ConfigureCommand;
use Sso\Command\EmergencyLoginCommand;
use Sso\Middleware\BackchannelLogoutMiddleware;
use Sso\Middleware\RequireSignInMiddleware;
use Sso\Middleware\SessionCheckMiddleware;
use Sso\Service\AuthenticationServiceProvider;

/**
 * Sign-in through the central identity provider, plug and play: loading the
 * plugin is enough. Its middleware hook wires itself into the application's
 * queue, every setting has a default, and the link to the identity provider is
 * kept in the plugin's own `sso_identities` table.
 *
 * Routes, all under `/sso`:
 *
 * - `GET /sso` — the sign-in page
 * - `GET /sso/login` — start sign-in; `?redirect=/local/path` returns there
 * - `GET /sso/callback` — the redirect URI registered at the provider
 * - `GET|POST /sso/logout` — sign out here and at the provider
 * - `POST /sso/backchannel-logout` — the provider's back-channel logout URI
 * - `GET|POST /sso/emergency/{token}` — single-use emergency sign-in
 * - `GET /sso/settings` (+ `POST update`, `POST test`) — Settings › Identity provider
 */
class SsoPlugin extends BasePlugin
{
    public const BACKCHANNEL_LOGOUT_PATH = '/sso/backchannel-logout';

    /**
     * Every setting and its default. An application overrides any of them in
     * `Configure::write('Sso', [...])` (usually `config/app.php`).
     *
     * - `userModel`: the application's users table.
     * - `fields`: column names on it (`email`, `name`, `is_active`); a null or
     *   missing column is not written.
     * - `loginUrl` / `loginRedirect`: the sign-in page, and where to go after.
     * - `layout`: the sign-in and emergency pages' layout (the plugin's own).
     * - `appName`: shown on the plugin's sign-in layout.
     * - `createUsers`: create a user on first sign-in when none matches.
     * - `settingsAccess`: who may open the settings page; a callable receiving
     *   the identity and the request. Null means any signed-in user, who is
     *   already in the application's required group.
     * - `authentication`: `auto` provides session authentication when the
     *   application has no AuthenticationMiddleware; `true`/`false` force it.
     * - `requireSignIn`: `auto` requires a signed-in session for every route
     *   when the plugin provides authentication; `true`/`false` force it.
     * - `publicPaths`: paths reachable without signing in (`fnmatch` patterns,
     *   e.g. `/health`, `/public-api/*`); the sign-in routes always are.
     * - `sessionsValidFromField` / `lastLoginField`: optional `users` columns to
     *   keep in step as well (1.1 applications); the plugin's own are in
     *   `sso_identities`.
     * - `cacheConfig`: the cache for the provider's discovery document and keys.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'userModel' => 'Users',
        'fields' => [],
        'loginUrl' => '/sso',
        'loginRedirect' => '/',
        'layout' => 'Sso.sso',
        'appName' => null,
        'createUsers' => true,
        'settingsAccess' => null,
        'authentication' => 'auto',
        'requireSignIn' => 'auto',
        'publicPaths' => [],
        'sessionsValidFromField' => null,
        'lastLoginField' => null,
        'cacheConfig' => 'default',
    ];

    protected ?string $name = 'Sso';

    protected bool $middlewareEnabled = true;

    protected bool $bootstrapEnabled = true;

    /**
     * Fills in every setting the application left out.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The application.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        self::applyDefaults();
    }

    /**
     * @return void
     */
    public static function applyDefaults(): void
    {
        Configure::write('Sso', array_replace(self::DEFAULTS, (array)Configure::read('Sso', [])));
    }

    /**
     * Wires the plugin into the application's middleware queue. Plugin
     * middleware runs after the application's own `middleware()`, so the
     * queue already holds the application's routing, body parsing, CSRF and
     * (perhaps) authentication; the plugin places itself around them and never
     * adds a second copy of something the application already has.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        // The back-channel logout is a signed server-to-server POST with no
        // CSRF token; answering it before the CSRF middleware means the
        // application needs no exemption.
        if (self::queueHas($middlewareQueue, CsrfProtectionMiddleware::class)) {
            $middlewareQueue->insertBefore(CsrfProtectionMiddleware::class, new BackchannelLogoutMiddleware());
        } else {
            self::insertAfterFirst($middlewareQueue, [RoutingMiddleware::class], new BackchannelLogoutMiddleware());
        }

        $providesAuthentication = self::providesAuthentication($middlewareQueue);
        if ($providesAuthentication) {
            self::insertAfterFirst(
                $middlewareQueue,
                [CsrfProtectionMiddleware::class, BodyParserMiddleware::class, RoutingMiddleware::class],
                new AuthenticationMiddleware(new AuthenticationServiceProvider()),
            );
        }

        if (!self::queueHas($middlewareQueue, SessionCheckMiddleware::class)) {
            $middlewareQueue->insertAfter(AuthenticationMiddleware::class, new SessionCheckMiddleware());
        }

        $require = Configure::read('Sso.requireSignIn');
        if ($require === true || ($require === 'auto' && $providesAuthentication)) {
            $middlewareQueue->insertAfter(SessionCheckMiddleware::class, new RequireSignInMiddleware());
        }

        return $middlewareQueue;
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin('Sso', ['path' => '/sso'], function (RouteBuilder $builder): void {
            $builder->connect('/', ['controller' => 'Sso', 'action' => 'index']);
            $builder->connect('/login', ['controller' => 'Sso', 'action' => 'login']);
            $builder->connect('/callback', ['controller' => 'Sso', 'action' => 'callback']);
            $builder->connect('/logout', ['controller' => 'Sso', 'action' => 'logout']);
            $builder->connect('/backchannel-logout', ['controller' => 'Sso', 'action' => 'backchannelLogout']);
            $builder->connect('/emergency/{token}', ['controller' => 'Sso', 'action' => 'emergency'])
                ->setPass(['token'])
                ->setPatterns(['token' => '[0-9a-f]{64}']);
            $builder->connect('/settings', ['controller' => 'Settings', 'action' => 'index']);
            $builder->connect('/settings/update', ['controller' => 'Settings', 'action' => 'update']);
            $builder->connect('/settings/test', ['controller' => 'Settings', 'action' => 'test']);
        });
    }

    /**
     * @param \Cake\Console\CommandCollection $commands The command collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands
            ->add(ConfigureCommand::defaultName(), ConfigureCommand::class)
            ->add(EmergencyLoginCommand::defaultName(), EmergencyLoginCommand::class);
    }

    /**
     * Whether a request is the provider's back-channel logout. 1.1 applications
     * exempt it from CSRF with this; 1.2 answers it before CSRF itself.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    public static function isCsrfExempt(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::BACKCHANNEL_LOGOUT_PATH;
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @return bool
     */
    private static function providesAuthentication(MiddlewareQueue $queue): bool
    {
        $setting = Configure::read('Sso.authentication');
        if (is_bool($setting)) {
            return $setting && !self::queueHas($queue, AuthenticationMiddleware::class);
        }

        return !self::queueHas($queue, AuthenticationMiddleware::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @param class-string $class The middleware class.
     * @return bool
     */
    private static function queueHas(MiddlewareQueue $queue, string $class): bool
    {
        foreach ($queue as $middleware) {
            if (is_a($middleware, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts after the first of the given classes found in the queue, or at
     * the end when none is.
     *
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @param array<class-string> $classes Candidates, in order of preference.
     * @param \Psr\Http\Server\MiddlewareInterface $middleware The middleware.
     * @return void
     */
    private static function insertAfterFirst(
        MiddlewareQueue $queue,
        array $classes,
        MiddlewareInterface $middleware,
    ): void {
        foreach ($classes as $class) {
            if (self::queueHas($queue, $class)) {
                $queue->insertAfter($class, $middleware);

                return;
            }
        }
        $queue->add($middleware);
    }
}
