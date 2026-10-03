<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Sso\Model\Entity\IdentitySourceType;

/**
 * @method \Sso\Model\Entity\IdentitySourceType get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class IdentitySourceTypesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('identity_source_types');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->setEntityClass(IdentitySourceType::class);
    }

    /**
     * The id of the row with the given code.
     *
     * @param string $code One of the `IdentitySourceType` code constants.
     * @return int
     */
    public function idForCode(string $code): int
    {
        /** @var \Sso\Model\Entity\IdentitySourceType $row */
        $row = $this->find()->where(['code' => $code])->firstOrFail();

        return $row->id;
    }
}
