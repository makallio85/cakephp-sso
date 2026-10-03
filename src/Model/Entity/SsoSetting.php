<?php
declare(strict_types=1);

namespace Sso\Model\Entity;

use Cake\ORM\Entity;

/**
 * The connection to the identity provider. There is at most one row.
 *
 * `client_secret` holds the encrypted value; read it through
 * `SsoSettingsTable::clientSecret()`.
 *
 * @property int $id
 * @property string $issuer_url
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $required_group
 * @property bool $is_enabled
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class SsoSetting extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'issuer_url' => true,
        'client_id' => true,
        'client_secret' => true,
        'required_group' => true,
        'is_enabled' => true,
    ];

    /**
     * @var array<string>
     */
    protected array $_hidden = ['client_secret'];
}
