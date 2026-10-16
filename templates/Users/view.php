<?php
/**
 * One user and their sign-in history. Bootstrap 5 markup; an application can
 * restyle it by overriding `templates/plugin/RockSoftware/Users/Users/view.php`.
 *
 * @var \Cake\View\View $this
 * @var \Cake\Datasource\EntityInterface $user
 * @var array{email: string, name: string|null, is_active: string|null, password: string|null} $fields
 * @var bool $hasActive
 * @var bool $isActive
 * @var bool $isSelf
 * @var \RockSoftware\Users\Model\Entity\Identity|null $identity
 * @var \RockSoftware\Users\Model\Entity\SessionEvent|null $lastSignIn
 * @var array<\RockSoftware\Users\Model\Entity\SessionEvent> $history
 * @var array<int, string> $actors
 * @var int $retentionDays
 * @var array<array{title: string, element: string, data?: array<string, mixed>}> $sections
 */
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Model\Entity\SignInMethodType;
use RockSoftware\Users\Service\UserProvisioner;

$name = UserProvisioner::displayName($user);
$this->assign('title', $name);
$id = (int)$user->get('id');
$format = fn($time): string => $time === null ? '' : $this->Time->format($time, 'd.M.yyyy HH.mm');
?>
<div class="rsu-user" style="max-width: 1200px">
    <p class="mb-2"><?= $this->Html->link('‹ ' . __d('rock_software_users', 'Users'), ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'index']) ?></p>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= h($name) ?></h1>
            <p class="text-body-secondary mb-0"><?= h((string)$user->get($fields['email'])) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?= $this->Form->postButton(
                __d('rock_software_users', 'End sessions'),
                ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'endSessions', $id],
                ['class' => 'btn btn-outline-secondary'],
            ) ?>
            <?php if ($hasActive && $isActive && !$isSelf) : ?>
                <?= $this->Form->postButton(
                    __d('rock_software_users', 'Deactivate'),
                    ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'deactivate', $id],
                    ['class' => 'btn btn-outline-danger', 'confirm' => __d('rock_software_users', 'Deactivate {0}? They are signed out of this application and cannot sign in until activated again.', $name)],
                ) ?>
            <?php elseif ($hasActive && !$isActive) : ?>
                <?= $this->Form->postButton(
                    __d('rock_software_users', 'Activate'),
                    ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'activate', $id],
                    ['class' => 'btn btn-outline-primary'],
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold"><?= __d('rock_software_users', 'Details') ?></h2>
                    <dl class="row mb-0 small">
                        <?php if ($hasActive) : ?>
                            <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Status') ?></dt>
                            <dd class="col-sm-7"><?= $isActive ? __d('rock_software_users', 'Active') : __d('rock_software_users', 'Deactivated') ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Rock Software ID') ?></dt>
                        <dd class="col-sm-7"><?= $identity === null
                            ? __d('rock_software_users', 'Not yet linked; linked on their first sign-in')
                            : __d('rock_software_users', 'Linked') ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Last signed in') ?></dt>
                        <dd class="col-sm-7"><?= $lastSignIn === null
                            ? __d('rock_software_users', 'Never')
                            : h($format($lastSignIn->created) . ($lastSignIn->sign_in_method_type === null ? '' : ', ' . SignInMethodType::label($lastSignIn->sign_in_method_type->value))) ?></dd>
                        <?php if ($user->has('created')) : ?>
                            <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Created') ?></dt>
                            <dd class="col-sm-7"><?= h($format($user->get('created'))) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </section>
        </div>
        <?php foreach ($sections as $section) : ?>
            <div class="col-md-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-semibold"><?= h($section['title']) ?></h2>
                        <?= $this->element($section['element'], ($section['data'] ?? []) + ['user' => $user]) ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex flex-wrap justify-content-between gap-2">
            <h2 class="h6 fw-semibold mb-0"><?= __d('rock_software_users', 'Sign-in history') ?></h2>
            <span class="small text-body-secondary"><?= __d('rock_software_users', 'Kept for {0} days', $retentionDays) ?></span>
        </div>
        <?php if ($history === []) : ?>
            <div class="card-body small text-body-secondary"><?= __d('rock_software_users', 'Nothing yet.') ?></div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead>
                        <tr class="text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Time') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Method') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Event') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'IP address') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $event) : ?>
                            <?php
                            $type = (string)$event->session_event_type?->value;
                            $refused = in_array($type, SessionEventType::REFUSALS, true);
                            $actor = $event->actor_user_id === null ? '' : ($actors[$event->actor_user_id] ?? __d('rock_software_users', 'a removed user'));
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= h($format($event->created)) ?></td>
                                <td><?= $event->sign_in_method_type === null ? '—' : h(SignInMethodType::label($event->sign_in_method_type->value)) ?></td>
                                <td style="color: <?= $refused ? '#a40e26' : ($type === SessionEventType::SIGNED_IN ? '#116329' : 'inherit') ?>"><?= h(SessionEventType::label($type, $actor)) ?></td>
                                <td class="text-body-secondary font-monospace"><?= h((string)$event->ip_address) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
