<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Sso\Model\Entity\SsoProvisioning;

/**
 * @method \Sso\Model\Entity\SsoProvisioning newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \Sso\Model\Entity\SsoProvisioning saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class SsoProvisioningsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_provisionings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoProvisioning::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * Whether a token id has been accepted before.
     *
     * @param string $jti The token id.
     * @return bool
     */
    public function used(string $jti): bool
    {
        return $this->exists(['jti' => $jti]);
    }

    /**
     * The most recent provisioning.
     *
     * @return \Sso\Model\Entity\SsoProvisioning|null
     */
    public function latest(): ?SsoProvisioning
    {
        /** @var \Sso\Model\Entity\SsoProvisioning|null */
        return $this->find()
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->first();
    }
}
