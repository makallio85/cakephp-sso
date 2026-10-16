<?php
declare(strict_types=1);

namespace Sso\Model\Entity;

use Cake\ORM\Entity;

/**
 * The link between one of the application's users and their identity provider
 * account.
 *
 * @property int $id
 * @property int $user_id The application's `users.id`.
 * @property string $external_id The provider's subject identifier.
 * @property \Cake\I18n\DateTime|null $synced_at
 * @property \Cake\I18n\DateTime|null $last_login_at
 * @property \Cake\I18n\DateTime|null $sessions_valid_from Sessions established before this have ended.
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class SsoIdentity extends Entity
{
    /**
     * Written by the plugin only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];
}
