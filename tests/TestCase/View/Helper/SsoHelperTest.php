<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\View\Helper;

use Authentication\Identity;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Sso\Session\SsoSession;
use Sso\Test\TestCase\FakeProvider;
use Sso\View\Helper\SsoHelper;

class SsoHelperTest extends TestCase
{
    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.Users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadPlugins(['Sso' => ['path' => dirname(__DIR__, 4) . DS]]);
        Router::reload();
        Router::createRouteBuilder('/')->scope('/', function ($routes): void {
            $routes->connect('/sso/logout', ['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'logout']);
        });
    }

    public function testNobodySignedInGetsNoItems(): void
    {
        $view = new View(new ServerRequest(['session' => new Session()]));

        $this->assertSame([], (new SsoHelper($view))->menuItems());
        $this->assertSame('', $view->element('Sso.user_menu'));
    }

    public function testTheItemsDescribeThePersonTheirAppsAndSignOut(): void
    {
        $this->fetchTable('Sso.SsoSettings')->store([
            'issuer_url' => FakeProvider::ISSUER,
            'client_id' => FakeProvider::CLIENT_ID,
            'client_secret' => FakeProvider::CLIENT_SECRET,
            'is_enabled' => true,
        ]);
        $view = new View($this->signedInRequest());

        $items = (new SsoHelper($view))->menuItems();

        $this->assertSame(['header', 'app', 'app', 'link', 'logout'], array_column($items, 'type'));
        $this->assertSame('Anna Example', $items[0]['label']);
        $this->assertStringStartsWith('anna@example.test · Signed in with Rock Software ID at ', $items[0]['detail']);
        $this->assertTrue($items[1]['current'], 'the application the person is in');
        $this->assertFalse($items[2]['current']);
        $this->assertSame('https://id.example.test/if/user/', $items[3]['url']);
        $this->assertSame('post', $items[4]['method']);
    }

    public function testTheElementRendersTheSameItems(): void
    {
        $html = (new View($this->signedInRequest()))->element('Sso.user_menu');

        $this->assertStringContainsString('<details class="sso-user-menu">', $html);
        $this->assertStringContainsString('>AE<', $html);
        $this->assertStringContainsString('href="https://messaging.example.test/"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('action="/sso/logout"', $html, 'sign-out is a POST');
        $this->assertStringNotContainsString('/if/user/', $html, 'no account link without a connection');
    }

    private function signedInRequest(): ServerRequest
    {
        $users = $this->fetchTable('Users');
        $user = $users->saveOrFail($users->newEntity([
            'email' => 'anna@example.test',
            'name' => 'Anna Example',
            'password' => '!sso:x',
            'is_active' => true,
        ], ['accessibleFields' => ['*' => true], 'validate' => false]));
        $session = new Session();
        $session->write([
            SsoSession::AUTHENTICATED => true,
            SsoSession::METHOD => SsoSession::METHOD_OIDC,
            SsoSession::ESTABLISHED_AT => time(),
            SsoSession::APPS => [
                ['name' => 'This App', 'url' => 'https://app.example.test/'],
                ['name' => 'Messaging', 'url' => 'https://messaging.example.test/'],
            ],
        ]);
        $request = new ServerRequest([
            'session' => $session,
            'environment' => ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'],
        ]);

        return $request->withAttribute('identity', new Identity($user));
    }
}
