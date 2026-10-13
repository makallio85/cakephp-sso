<?php
/**
 * Settings › Identity provider. Bootstrap 5 markup; an application can restyle
 * it by overriding `templates/plugin/Sso/Settings/index.php`.
 *
 * @var \Cake\View\View $this
 * @var array<string, mixed> $values
 * @var bool $configured
 * @var string|null $problem
 * @var bool $issuerInvalid
 * @var string $callbackUrl
 * @var \Sso\Model\Entity\SsoSettingChange|null $lastChange
 * @var string|null $lastChangedBy
 */
$this->assign('title', __d('sso', 'Identity provider'));
?>
<div class="sso-settings" style="max-width: 980px">
    <div class="mb-3">
        <h1 class="h4 mb-1"><?= __d('sso', 'Identity provider') ?></h1>
        <p class="text-body-secondary mb-0"><?= __d('sso', 'People sign in only through Rock Software ID. These settings say which server and which client.') ?></p>
    </div>

    <?php if (!$configured) : ?>
        <div class="alert alert-warning" role="status">
            <?= __d('sso', 'Not configured yet. Enter the connection below; it is saved once the server answers.') ?>
        </div>
    <?php elseif ($problem === null) : ?>
        <div class="alert alert-success" role="status">
            <strong><?= __d('sso', 'Connected.') ?></strong> <?= __d('sso', 'The server answers its discovery document.') ?>
        </div>
    <?php elseif (!$issuerInvalid) : ?>
        <div class="alert alert-danger" role="status">
            <strong><?= __d('sso', 'The stored server does not answer.') ?></strong> <?= h($problem) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <?= $this->Form->create(null, ['url' => ['plugin' => 'Sso', 'controller' => 'Settings', 'action' => 'update']]) ?>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="issuer-url"><?= __d('sso', 'Identity server address') ?></label>
                <?= $this->Form->control('issuer_url', [
                    'label' => false,
                    'type' => 'url',
                    'id' => 'issuer-url',
                    'value' => $values['issuer_url'] ?? '',
                    'required' => true,
                    'placeholder' => 'https://id.rocksoftware.fi/application/o/<slug>/',
                    'class' => 'form-control' . ($issuerInvalid ? ' is-invalid' : ''),
                    'aria-describedby' => 'issuer-url-help',
                ]) ?>
                <div id="issuer-url-help" class="form-text<?= $issuerInvalid ? ' text-danger' : '' ?>">
                    <?= $issuerInvalid
                        ? __d('sso', 'This address did not answer as an identity server. Check that the path ends in the application\'s slug.')
                        : __d('sso', 'The issuer URL: https://id.rocksoftware.fi/application/o/ followed by the application\'s slug.') ?>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="client-id"><?= __d('sso', 'Client ID') ?></label>
                    <?php // `type` is explicit: a field ending in _id otherwise renders as an empty select. ?>
                    <?= $this->Form->control('client_id', [
                        'label' => false, 'type' => 'text', 'id' => 'client-id', 'class' => 'form-control',
                        'required' => true, 'value' => $values['client_id'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="required-group"><?= __d('sso', 'Required group') ?></label>
                    <?= $this->Form->control('required_group', [
                        'label' => false, 'type' => 'text', 'id' => 'required-group', 'class' => 'form-control',
                        'value' => $values['required_group'] ?? '',
                    ]) ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="client-secret"><?= __d('sso', 'Client secret') ?></label>
                <?= $this->Form->control('client_secret', [
                    'label' => false, 'type' => 'password', 'id' => 'client-secret', 'class' => 'form-control',
                    'value' => '', 'autocomplete' => 'new-password',
                    'placeholder' => $configured ? __d('sso', 'Stored — leave blank to keep it') : __d('sso', 'Required'),
                    'aria-describedby' => 'client-secret-help',
                ]) ?>
                <div id="client-secret-help" class="form-text"><?= __d('sso', 'Stored encrypted and never shown. In Authentik: Applications › Providers › the provider › Edit.') ?></div>
            </div>
            <div class="bg-light rounded p-3 mb-3">
                <div class="small fw-semibold text-secondary mb-1"><?= __d('sso', 'Redirect URI to register at the provider') ?></div>
                <code class="small text-break"><?= h($callbackUrl) ?></code>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= $this->Form->button(__d('sso', 'Save'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->button(__d('sso', 'Test connection'), [
                    'class' => 'btn btn-outline-secondary',
                    'formaction' => $this->Url->build(['plugin' => 'Sso', 'controller' => 'Settings', 'action' => 'test']),
                ]) ?>
                <span class="small text-body-secondary"><?= __d('sso', 'Save checks the server first. Settings that cannot reach it are not saved, so nobody is locked out.') ?></span>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <?php if ($lastChange !== null) : ?>
        <p class="small text-body-secondary mt-3 mb-0">
            <?= __d('sso', 'Last changed by {0}, {1}.', h($lastChangedBy ?? __d('sso', 'a removed user')), h($this->Time->format($lastChange->created, 'd.M.yyyy HH.mm'))) ?>
        </p>
    <?php endif; ?>
</div>
