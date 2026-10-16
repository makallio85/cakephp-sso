<?php
declare(strict_types=1);

namespace Sso\Test\TestCase\Middleware;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The test application has no authentication of its own, so the plugin
 * provides it and requires sign-in for every route that is not public.
 */
class RequireSignInMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'plugin.Sso.SsoSettings',
        'plugin.Sso.SsoIdentities',
        'plugin.Sso.Users',
    ];

    public function testAnonymousPageRequestIsSentToSignInAndBack(): void
    {
        $this->get('/dashboard?tab=2');

        $this->assertRedirect('/sso?redirect=%2Fdashboard%3Ftab%3D2');
    }

    public function testAnonymousJsonRequestGets401(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->get('/dashboard');

        $this->assertResponseCode(401);
        $this->assertContentType('application/json');
    }

    public function testPublicPathsPass(): void
    {
        $this->get('/health');
        $this->assertResponseOk();
        $this->assertResponseEquals('ok');

        $this->get('/sso');
        $this->assertResponseOk();
    }

    public function testSettingsPageIsNotPublic(): void
    {
        $this->get('/sso/settings');

        $this->assertRedirect('/sso?redirect=%2Fsso%2Fsettings');
    }

    public function testSignInPageUsesThePluginsLayout(): void
    {
        $this->get('/sso');

        $this->assertResponseContains('<p class="sso-app">Test App</p>');
        $this->assertResponseContains('<main class="sso-card">');
    }
}
