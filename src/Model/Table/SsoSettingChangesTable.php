<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Sso\Model\Entity\SsoSettingChange;

/**
 * @method \Sso\Model\Entity\SsoSettingChange newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \Sso\Model\Entity\SsoSettingChange saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class SsoSettingChangesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_setting_changes');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoSettingChange::class);
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }

    /**
     * The most recent change.
     *
     * @return \Sso\Model\Entity\SsoSettingChange|null
     */
    public function latest(): ?SsoSettingChange
    {
        /** @var \Sso\Model\Entity\SsoSettingChange|null */
        return $this->find()
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->first();
    }
}
