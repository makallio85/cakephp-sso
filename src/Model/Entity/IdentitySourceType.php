<?php
declare(strict_types=1);

namespace Sso\Model\Entity;

use Cake\ORM\Entity;

/**
 * Where a user row's identity comes from.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property int $sort_order
 */
class IdentitySourceType extends Entity
{
    /** A local account, signed in with the application's own form. */
    public const LOCAL = 'local';

    /** A cache of an identity provider account, keyed by `external_id`. */
    public const SSO = 'sso';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'code' => true,
        'label' => true,
        'sort_order' => true,
    ];
}
