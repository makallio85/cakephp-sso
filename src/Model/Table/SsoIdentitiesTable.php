<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Sso\Model\Entity\SsoIdentity;

/**
 * @method \Sso\Model\Entity\SsoIdentity newEmptyEntity()
 * @method \Sso\Model\Entity\SsoIdentity saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class SsoIdentitiesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_identities');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoIdentity::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * @param string $externalId The provider's subject identifier.
     * @return \Sso\Model\Entity\SsoIdentity|null
     */
    public function findByExternalId(string $externalId): ?SsoIdentity
    {
        /** @var \Sso\Model\Entity\SsoIdentity|null */
        return $this->find()->where(['external_id' => $externalId])->first();
    }

    /**
     * @param int $userId The application's user id.
     * @return \Sso\Model\Entity\SsoIdentity|null
     */
    public function findForUser(int $userId): ?SsoIdentity
    {
        /** @var \Sso\Model\Entity\SsoIdentity|null */
        return $this->find()->where(['user_id' => $userId])->first();
    }
}
