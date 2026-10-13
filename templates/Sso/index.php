<?php
/**
 * The sign-in page. Rock Software ID is the only way in, so it has one button.
 * A failed or refused sign-in comes back here with its reason as a flash
 * message, which the layout renders. An application gives it its look by overriding this template at
 * `templates/plugin/Sso/Sso/index.php`.
 *
 * @var \Cake\View\View $this
 * @var bool $ssoEnabled
 * @var array<string, mixed> $signInUrl
 */
$this->assign('title', __d('sso', 'Sign in'));
?>
<?php if ($ssoEnabled) : ?>
    <p>
        <?= $this->Html->link(
            __d('sso', 'Sign in with Rock Software ID'),
            $signInUrl,
            ['class' => 'btn btn-primary btn-lg w-100'],
        ) ?>
    </p>
<?php else : ?>
    <p class="alert alert-warning" role="alert">
        <?= __d('sso', 'Sign-in is not configured yet. Connect this deployment to Rock Software ID with {0} in the application container.', '<code>bin/cake sso configure</code>') ?>
    </p>
<?php endif; ?>
