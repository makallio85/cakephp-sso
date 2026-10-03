<?php
/**
 * Confirmation for a single-use emergency sign-in link.
 *
 * @var \Cake\View\View $this
 */
?>
<h1>Emergency sign-in</h1>
<p>This link signs you in once, without Rock Software ID. Use it only when Rock Software ID is unavailable.</p>
<?= $this->Form->create(null) ?>
<?= $this->Form->button('Sign in') ?>
<?= $this->Form->end() ?>
