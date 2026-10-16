<?php
declare(strict_types=1);

namespace Sso\Model\Entity;

use Cake\ORM\Entity;

/**
 * One row of the sign-in history.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property int $sso_session_event_type_id
 * @property int|null $sso_sign_in_method_type_id
 * @property int|null $actor_user_id
 * @property string|null $ip_address
 * @property \Cake\I18n\DateTime $created
 * @property \Sso\Model\Entity\SsoSessionEventType|null $sso_session_event_type
 * @property \Sso\Model\Entity\SsoSignInMethodType|null $sso_sign_in_method_type
 */
class SsoSessionEvent extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => true,
        'email' => true,
        'sso_session_event_type_id' => true,
        'sso_sign_in_method_type_id' => true,
        'actor_user_id' => true,
        'ip_address' => true,
    ];
}
