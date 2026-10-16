<?php
declare(strict_types=1);

namespace Sso\Service;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Model\Entity\SsoSignInMethodType;

/**
 * The identity provider's back-channel logout: a signed logout token naming a
 * subject whose sessions have ended. Moves the identity's
 * `sessions_valid_from` forward, which the session check turns into a
 * sign-out on that person's next request.
 */
class BackchannelLogout
{
    use LocatorAwareTrait;

    public const EVENT = 'Sso.backchannelLogout';

    /**
     * @param mixed $token The `logout_token` form field.
     * @return int The HTTP status to answer with.
     */
    public function handle(mixed $token): int
    {
        /** @var \Sso\Model\Table\SsoSettingsTable $settings */
        $settings = $this->fetchTable('Sso.SsoSettings');
        $setting = $settings->enabled();
        if ($setting === null || !is_string($token)) {
            return 400;
        }

        try {
            $claims = (new OidcClient($setting, $settings->clientSecret($setting)))->validateLogoutToken($token);
        } catch (SsoException $e) {
            Log::warning('SSO back-channel logout rejected: ' . $e->getMessage());

            return 400;
        }

        $subject = $claims['sub'] ?? null;
        if (!is_string($subject)) {
            return 200;
        }
        /** @var \Sso\Model\Table\SsoIdentitiesTable $identities */
        $identities = $this->fetchTable('Sso.SsoIdentities');
        $identity = $identities->findByExternalId($subject);
        if ($identity === null) {
            return 200;
        }

        $now = DateTime::now();
        $identity->sessions_valid_from = $now;
        $identities->saveOrFail($identity);

        $provisioner = new UserProvisioner();
        $user = $provisioner->users()->find()->where(['id' => $identity->user_id])->first();
        if ($user !== null) {
            // A 1.1 application may still read its own column.
            $legacyField = Configure::read('Sso.sessionsValidFromField');
            if (is_string($legacyField) && $provisioner->users()->getSchema()->hasColumn($legacyField)) {
                $user->set($legacyField, $now, ['guard' => false]);
                $provisioner->users()->saveOrFail($user, ['validate' => false]);
            }
            (new SignInHistory())->record(SsoSessionEventType::SIGNED_OUT_BY_PROVIDER, [
                'user_id' => (int)$user->get('id'),
                'method' => SsoSignInMethodType::OIDC,
            ]);
            EventManager::instance()->dispatch(new Event(self::EVENT, $this, ['user' => $user]));
        }

        return 200;
    }
}
