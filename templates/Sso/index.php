<?php
/**
 * The sign-in page: Rock Software ID, and the email and password form while
 * password sign-in is on. A failed or refused sign-in comes back here with
 * its reason as a flash message, which the layout renders. An application
 * gives it its look by overriding this template at
 * `templates/plugin/Sso/Sso/index.php`, or by naming its own layout in
 * `Sso.layout`.
 *
 * @var \Cake\View\View $this
 * @var bool $ssoEnabled
 * @var bool $passwordSignIn
 * @var string|null $redirect
 * @var string $email
 * @var array<string, mixed> $signInUrl
 */
$this->assign('title', __d('sso', 'Sign in'));
?>
<?php if ($ssoEnabled) : ?>
    <?= $this->Html->link(
        __d('sso', 'Sign in with Rock Software ID'),
        $signInUrl,
        ['class' => 'sso-button btn btn-primary btn-lg w-100'],
    ) ?>
<?php endif; ?>
<?php if ($passwordSignIn) : ?>
    <?php if ($ssoEnabled) : ?>
        <p class="sso-divider"><span><?= __d('sso', 'or with your email address and password') ?></span></p>
    <?php endif; ?>
    <?= $this->Form->create(null, [
        'url' => ['plugin' => 'Sso', 'controller' => 'Sso', 'action' => 'password', 'prefix' => false],
        'class' => 'sso-password-form',
    ]) ?>
    <?= $this->Form->hidden('redirect', ['value' => (string)$redirect]) ?>
    <?= $this->Form->control('email', [
        'type' => 'email',
        'label' => __d('sso', 'Email address'),
        'value' => $email,
        'required' => true,
        'autocomplete' => 'username',
        'class' => 'form-control',
    ]) ?>
    <?= $this->Form->control('password', [
        'type' => 'password',
        'label' => __d('sso', 'Password'),
        'value' => '',
        'required' => true,
        'autocomplete' => 'current-password',
        'class' => 'form-control',
    ]) ?>
    <?= $this->Form->button(__d('sso', 'Sign in'), [
        'class' => ($ssoEnabled ? 'sso-button sso-button-secondary btn btn-outline-secondary' : 'sso-button btn btn-primary') . ' w-100',
    ]) ?>
    <?= $this->Form->end() ?>
    <p class="sso-hint">
        <?= $ssoEnabled
            ? __d('sso', 'Forgot your password? Ask an administrator to set a new one, or sign in with Rock Software ID.')
            : __d('sso', 'Forgot your password? Ask an administrator to set a new one.') ?>
    </p>
<?php endif; ?>
<?php if (!$ssoEnabled && !$passwordSignIn) : ?>
    <p class="sso-notice alert alert-warning" role="alert">
        <?= __d('sso', 'Sign-in is not configured yet. Connect this deployment to Rock Software ID with {0} in the application container.', '<code>bin/cake sso configure</code>') ?>
    </p>
<?php endif; ?>
