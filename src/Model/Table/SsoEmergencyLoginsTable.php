<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Sso\Model\Entity\SsoEmergencyLogin;

class SsoEmergencyLoginsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_emergency_logins');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoEmergencyLogin::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * Issues a link for the user and returns the raw token, which is shown once
     * and never stored.
     *
     * @param int $userId The user the link signs in.
     * @param int $minutes How long the link stays valid.
     * @return string
     */
    public function issue(int $userId, int $minutes): string
    {
        $token = bin2hex(random_bytes(32));
        $this->saveOrFail($this->newEntity([
            'user_id' => $userId,
            'token_hash' => self::hash($token),
            'expires_at' => DateTime::now()->addMinutes($minutes),
        ]));

        return $token;
    }

    /**
     * The unused, unexpired link for a token, or null.
     *
     * @param string $token The raw token from the URL.
     * @return \Sso\Model\Entity\SsoEmergencyLogin|null
     */
    public function findUsable(string $token): ?SsoEmergencyLogin
    {
        /** @var \Sso\Model\Entity\SsoEmergencyLogin|null */
        return $this->find()
            ->where([
                'token_hash' => self::hash($token),
                'used_at IS' => null,
                'expires_at >' => DateTime::now(),
            ])
            ->first();
    }

    /**
     * Marks a link used. The update is conditional, so of two concurrent
     * requests carrying the same token only one succeeds.
     *
     * @param \Sso\Model\Entity\SsoEmergencyLogin $login The link.
     * @return bool Whether this call consumed it.
     */
    public function consume(SsoEmergencyLogin $login): bool
    {
        return $this->updateAll(
            ['used_at' => DateTime::now()],
            ['id' => $login->id, 'used_at IS' => null],
        ) === 1;
    }

    /**
     * @param string $token The raw token.
     * @return string
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
