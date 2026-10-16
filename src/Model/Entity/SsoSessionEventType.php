<?php
declare(strict_types=1);

namespace Sso\Model\Entity;

use Cake\ORM\Entity;

/**
 * What a sign-in history row records. The rows are system rows; the plugin
 * binds to them by value.
 *
 * @property int $id
 * @property string $value
 * @property string $label
 * @property int $sort_order
 */
class SsoSessionEventType extends Entity
{
    public const SIGNED_IN = 'signed_in';
    public const WRONG_PASSWORD = 'wrong_password';
    public const UNKNOWN_EMAIL = 'unknown_email';
    public const USER_INACTIVE = 'user_inactive';
    public const NOT_IN_GROUP = 'not_in_group';
    public const THROTTLED = 'throttled';
    public const PASSWORD_SIGN_IN_OFF = 'password_sign_in_off';
    public const SIGN_IN_FAILED = 'sign_in_failed';
    public const SIGNED_OUT = 'signed_out';
    public const SIGNED_OUT_BY_PROVIDER = 'signed_out_by_provider';
    public const SESSION_EXPIRED = 'session_expired';
    public const SESSIONS_ENDED = 'sessions_ended';
    public const USER_DEACTIVATED = 'user_deactivated';
    public const USER_ACTIVATED = 'user_activated';

    /**
     * Refused password attempts, which the throttle counts.
     *
     * @var array<string>
     */
    public const PASSWORD_FAILURES = [self::WRONG_PASSWORD, self::UNKNOWN_EMAIL, self::USER_INACTIVE];

    /**
     * Every refused or failed sign-in.
     *
     * @var array<string>
     */
    public const REFUSALS = [
        self::WRONG_PASSWORD,
        self::UNKNOWN_EMAIL,
        self::USER_INACTIVE,
        self::NOT_IN_GROUP,
        self::THROTTLED,
        self::PASSWORD_SIGN_IN_OFF,
        self::SIGN_IN_FAILED,
    ];

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'value' => true,
        'label' => true,
        'sort_order' => true,
    ];

    /**
     * The label in the application's language.
     *
     * @param string $value One of the value constants.
     * @param string $actor Who did it, for an administrator's action.
     * @return string
     */
    public static function label(string $value, string $actor = ''): string
    {
        return match ($value) {
            self::SIGNED_IN => __d('sso', 'Signed in'),
            self::WRONG_PASSWORD => __d('sso', 'Wrong password'),
            self::UNKNOWN_EMAIL => __d('sso', 'Unknown email address'),
            self::USER_INACTIVE => __d('sso', 'Refused: user deactivated'),
            self::NOT_IN_GROUP => __d('sso', 'Refused: not in the required group'),
            self::THROTTLED => __d('sso', 'Too many attempts'),
            self::PASSWORD_SIGN_IN_OFF => __d('sso', 'Refused: password sign-in is off'),
            self::SIGN_IN_FAILED => __d('sso', 'Sign-in failed'),
            self::SIGNED_OUT => __d('sso', 'Signed out'),
            self::SIGNED_OUT_BY_PROVIDER => __d('sso', 'Signed out by Rock Software ID'),
            self::SESSION_EXPIRED => __d('sso', 'Session expired'),
            self::SESSIONS_ENDED => __d('sso', 'Sessions ended by {0}', $actor),
            self::USER_DEACTIVATED => __d('sso', 'Deactivated by {0}', $actor),
            self::USER_ACTIVATED => __d('sso', 'Activated by {0}', $actor),
            default => $value,
        };
    }
}
