<?php
declare(strict_types=1);

namespace Sso\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Sso\Model\Entity\SsoSetting;
use Sso\Service\SecretCipher;

class SsoSettingsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('sso_settings');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SsoSetting::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->requirePresence('issuer_url', 'create')
            ->notEmptyString('issuer_url')
            ->add('issuer_url', 'https', [
                'rule' => fn($value) => is_string($value) && str_starts_with($value, 'https://'),
                'message' => 'The issuer must be an https URL.',
            ])
            ->requirePresence('client_id', 'create')
            ->notEmptyString('client_id')
            ->requirePresence('client_secret', 'create')
            ->notEmptyString('client_secret')
            ->allowEmptyString('required_group');
    }

    /**
     * The connection, enabled or not, or null when none has been configured.
     *
     * @return \Sso\Model\Entity\SsoSetting|null
     */
    public function current(): ?SsoSetting
    {
        /** @var \Sso\Model\Entity\SsoSetting|null */
        return $this->find()->orderBy(['id' => 'ASC'])->first();
    }

    /**
     * The connection when sign-in through the identity provider is switched on.
     *
     * @return \Sso\Model\Entity\SsoSetting|null
     */
    public function enabled(): ?SsoSetting
    {
        $setting = $this->current();

        return $setting !== null && $setting->is_enabled ? $setting : null;
    }

    /**
     * Writes the one connection row, encrypting the client secret.
     *
     * @param array<string, mixed> $data issuer_url, client_id, client_secret (plain), required_group, is_enabled.
     * @return \Sso\Model\Entity\SsoSetting
     */
    public function store(array $data): SsoSetting
    {
        if (isset($data['client_secret']) && is_string($data['client_secret']) && $data['client_secret'] !== '') {
            $data['client_secret'] = SecretCipher::encrypt($data['client_secret']);
        }
        if (isset($data['issuer_url']) && is_string($data['issuer_url'])) {
            $data['issuer_url'] = rtrim($data['issuer_url'], '/') . '/';
        }

        $setting = $this->current() ?? $this->newEmptyEntity();
        $setting = $this->patchEntity($setting, $data);

        return $this->saveOrFail($setting);
    }

    /**
     * The decrypted client secret.
     *
     * @param \Sso\Model\Entity\SsoSetting $setting The connection.
     * @return string
     */
    public function clientSecret(SsoSetting $setting): string
    {
        return SecretCipher::decrypt($setting->client_secret);
    }
}
