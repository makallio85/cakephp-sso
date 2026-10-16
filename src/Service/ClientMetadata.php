<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Core\Configure;
use Composer\InstalledVersions;
use Sso\Middleware\RequireSignInMiddleware;
use Sso\SsoPlugin;
use Throwable;

/**
 * What the identity provider must register for this application, as paths
 * relative to the application's own address: where sign-in returns, where
 * signing out returns, the back-channel logout and provisioning endpoints.
 */
final class ClientMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $loginUrl = (string)Configure::read('Sso.loginUrl', '/sso');
        $logout = array_values(array_unique([$loginUrl, '/sso']));

        try {
            $version = InstalledVersions::getPrettyVersion('makallio85/cakephp-sso');
        } catch (Throwable) {
            $version = null;
        }

        return [
            'redirect_uri' => '/sso/callback',
            'post_logout_redirect_uris' => $logout,
            'backchannel_logout_uri' => SsoPlugin::BACKCHANNEL_LOGOUT_PATH,
            'provision_uri' => Provisioning::config() === null ? null : SsoPlugin::PROVISION_PATH,
            'password_sign_in' => Configure::read('Sso.passwordSignIn') === true,
            'plugin_version' => $version,
            'sign_in_paths' => RequireSignInMiddleware::SIGN_IN_PATHS,
        ];
    }
}
