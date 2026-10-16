<?php
declare(strict_types=1);

namespace Sso\View\Helper;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Helper;
use Sso\Model\Entity\SsoSignInMethodType;
use Sso\Service\UserProvisioner;
use Sso\Session\SsoSession;
use Throwable;

/**
 * The signed-in person's menu, for the application's layout.
 *
 * `menuItems()` returns the entries as data, translated, for an application
 * that renders its own menu. The `Sso.user_menu` element renders the same
 * list as a ready dropdown:
 *
 *     <?= $this->element('Sso.user_menu') ?>
 *
 * Entry types: `header` (`label`, `detail`), `app` (`label`, `url`,
 * `current`), `link` (`label`, `url`) and `logout` (`label`, `url`,
 * `method` = `post`: render it with `FormHelper::postLink()`).
 */
class SsoHelper extends Helper
{
    use LocatorAwareTrait;

    /**
     * @return array<array<string, mixed>> Empty when nobody is signed in.
     */
    public function menuItems(): array
    {
        $request = $this->getView()->getRequest();
        $identity = $request->getAttribute('identity');
        $user = is_object($identity) && method_exists($identity, 'getOriginalData')
            ? $identity->getOriginalData()
            : null;
        if (!$user instanceof EntityInterface) {
            return [];
        }
        $session = $request->getSession();

        $detail = [(string)$user->get(UserProvisioner::fields()['email'])];
        $method = $session->read(SsoSession::METHOD);
        $establishedAt = $session->read(SsoSession::ESTABLISHED_AT);
        if (is_string($method) && SsoSession::isPluginSession($session) && is_int($establishedAt)) {
            $detail[] = __d(
                'sso',
                'Signed in with {0} at {1}',
                SsoSignInMethodType::label($method),
                DateTime::createFromTimestamp($establishedAt)->i18nFormat('HH.mm'),
            );
        }
        $items = [[
            'type' => 'header',
            'label' => UserProvisioner::displayName($user),
            'detail' => implode(' · ', $detail),
        ]];

        $host = $request->host();
        foreach ((array)$session->read(SsoSession::APPS) as $app) {
            if (!is_array($app) || !is_string($app['name'] ?? null) || !is_string($app['url'] ?? null)) {
                continue;
            }
            $items[] = [
                'type' => 'app',
                'label' => $app['name'],
                'url' => $app['url'],
                'current' => parse_url($app['url'], PHP_URL_HOST) === $host,
            ];
        }

        $accountUrl = $this->accountUrl();
        if ($accountUrl !== null) {
            $items[] = ['type' => 'link', 'label' => __d('sso', 'Your Rock Software ID account'), 'url' => $accountUrl];
        }
        $items[] = [
            'type' => 'logout',
            'label' => __d('sso', 'Sign out'),
            'url' => ['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'logout', 'prefix' => false],
            'method' => 'post',
        ];

        return $items;
    }

    /**
     * The identity provider's own page for the person's account, when the
     * application is connected.
     *
     * @return string|null
     */
    private function accountUrl(): ?string
    {
        try {
            /** @var \Sso\Model\Table\SsoSettingsTable $settings */
            $settings = $this->fetchTable('Sso.SsoSettings');
            $setting = $settings->enabled();
        } catch (Throwable) {
            return null;
        }
        $parts = $setting === null ? false : parse_url($setting->issuer_url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }

        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/if/user/';
    }
}
