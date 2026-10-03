<?php
declare(strict_types=1);

namespace Sso;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Routing\RouteBuilder;
use Psr\Http\Message\ServerRequestInterface;
use Sso\Command\ConfigureCommand;
use Sso\Command\EmergencyLoginCommand;

/**
 * Sign-in through the central identity provider.
 *
 * Routes, all under `/sso`:
 *
 * - `GET /sso/login` — start sign-in; `?redirect=/local/path` returns there
 * - `GET /sso/callback` — the redirect URI registered at the provider
 * - `GET|POST /sso/logout` — sign out here and at the provider
 * - `POST /sso/backchannel-logout` — the provider's back-channel logout URI
 * - `GET|POST /sso/emergency/{token}` — single-use emergency sign-in
 */
class SsoPlugin extends BasePlugin
{
    public const BACKCHANNEL_LOGOUT_PATH = '/sso/backchannel-logout';

    protected ?string $name = 'Sso';

    protected bool $middlewareEnabled = false;

    protected bool $bootstrapEnabled = false;

    /**
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin('Sso', ['path' => '/sso'], function (RouteBuilder $builder): void {
            $builder->connect('/login', ['controller' => 'Sso', 'action' => 'login']);
            $builder->connect('/callback', ['controller' => 'Sso', 'action' => 'callback']);
            $builder->connect('/logout', ['controller' => 'Sso', 'action' => 'logout']);
            $builder->connect('/backchannel-logout', ['controller' => 'Sso', 'action' => 'backchannelLogout']);
            $builder->connect('/emergency/{token}', ['controller' => 'Sso', 'action' => 'emergency'])
                ->setPass(['token'])
                ->setPatterns(['token' => '[0-9a-f]{64}']);
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
     * Whether a request must skip CSRF protection: the back-channel logout is a
     * server-to-server POST from the provider and carries a signed token instead.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    public static function isCsrfExempt(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::BACKCHANNEL_LOGOUT_PATH;
    }
}
