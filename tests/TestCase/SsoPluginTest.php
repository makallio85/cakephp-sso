<?php
declare(strict_types=1);

namespace Sso\Test\TestCase;

use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\TestSuite\TestCase;
use Sso\Middleware\BackchannelLogoutMiddleware;
use Sso\Middleware\RequireSignInMiddleware;
use Sso\Middleware\SessionCheckMiddleware;
use Sso\Service\AuthenticationServiceProvider;
use Sso\SsoPlugin;
use TestApp\Application;

class SsoPluginTest extends TestCase
{
    protected function tearDown(): void
    {
        SsoPlugin::applyDefaults();
        Configure::write('Sso.authentication', 'auto');
        Configure::write('Sso.requireSignIn', 'auto');
        parent::tearDown();
    }

    public function testWiresAnApplicationWithoutAuthentication(): void
    {
        $queue = (new SsoPlugin())->middleware($this->applicationQueue());

        $this->assertSame([
            RoutingMiddleware::class,
            BodyParserMiddleware::class,
            BackchannelLogoutMiddleware::class,
            CsrfProtectionMiddleware::class,
            AuthenticationMiddleware::class,
            SessionCheckMiddleware::class,
            RequireSignInMiddleware::class,
        ], $this->classes($queue));
    }

    public function testKeepsTheApplicationsOwnAuthentication(): void
    {
        $own = new AuthenticationMiddleware(new AuthenticationServiceProvider());
        $queue = (new SsoPlugin())->middleware($this->applicationQueue()->add($own));

        $this->assertSame([
            RoutingMiddleware::class,
            BodyParserMiddleware::class,
            BackchannelLogoutMiddleware::class,
            CsrfProtectionMiddleware::class,
            AuthenticationMiddleware::class,
            SessionCheckMiddleware::class,
        ], $this->classes($queue), 'no second authentication, and the application decides what is public');
    }

    public function testDoesNotAddASecondSessionCheck(): void
    {
        $queue = $this->applicationQueue()
            ->add(new AuthenticationMiddleware(new AuthenticationServiceProvider()))
            ->add(new SessionCheckMiddleware());

        $classes = $this->classes((new SsoPlugin())->middleware($queue));

        $this->assertSame(1, array_count_values($classes)[SessionCheckMiddleware::class]);
    }

    public function testRequireSignInCanBeSwitchedOff(): void
    {
        Configure::write('Sso.requireSignIn', false);

        $classes = $this->classes((new SsoPlugin())->middleware($this->applicationQueue()));

        $this->assertNotContains(RequireSignInMiddleware::class, $classes);
        $this->assertContains(AuthenticationMiddleware::class, $classes);
    }

    public function testDefaultsFillWhatTheApplicationLeftOut(): void
    {
        Configure::write('Sso', ['loginRedirect' => '/home']);

        SsoPlugin::applyDefaults();

        $this->assertSame('/home', Configure::read('Sso.loginRedirect'));
        $this->assertSame('/sso', Configure::read('Sso.loginUrl'));
        $this->assertSame('Users', Configure::read('Sso.userModel'));
    }

    /**
     * @return \Cake\Http\MiddlewareQueue
     */
    private function applicationQueue(): MiddlewareQueue
    {
        return (new MiddlewareQueue())
            ->add(new RoutingMiddleware(new Application(CONFIG)))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware());
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $queue The queue.
     * @return list<string>
     */
    private function classes(MiddlewareQueue $queue): array
    {
        $classes = [];
        foreach ($queue as $middleware) {
            $classes[] = $middleware::class;
        }

        return $classes;
    }
}
