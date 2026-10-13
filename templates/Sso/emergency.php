<?php
/**
 * Confirmation for a single-use emergency sign-in link.
 *
 * @var \Cake\View\View $this
 */
$this->assign('title', __d('sso', 'Emergency sign-in'));
?>
<h1><?= __d('sso', 'Emergency sign-in') ?></h1>
<p><?= __d('sso', 'This link signs you in once, without Rock Software ID. Use it only when Rock Software ID is unavailable.') ?></p>
<?= $this->Form->create(null) ?>
<?= $this->Form->button(__d('sso', 'Sign in'), ['class' => 'btn btn-primary']) ?>
<?= $this->Form->end() ?>
