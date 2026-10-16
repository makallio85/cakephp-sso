<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Sso\Model\Entity\SsoSignInMethodType;

class SsoSignInMethodTypesTable extends Table
{
    /**
     * @var array<string, int>
     */
    private array $ids = [];

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_sign_in_method_types');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoSignInMethodType::class);
    }

    /**
     * @param string $value One of the `SsoSignInMethodType` value constants.
     * @return int
     */
    public function idFor(string $value): int
    {
        if (!isset($this->ids[$value])) {
            /** @var \Sso\Model\Entity\SsoSignInMethodType $row */
            $row = $this->find()->where(['value' => $value])->firstOrFail();
            $this->ids[$value] = $row->id;
        }

        return $this->ids[$value];
    }
}
