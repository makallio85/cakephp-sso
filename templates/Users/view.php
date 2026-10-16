<?php
/**
 * One user and their sign-in history. Bootstrap 5 markup; an application can
 * restyle it by overriding `templates/plugin/Sso/Users/view.php`.
 *
 * @var \Cake\View\View $this
 * @var \Cake\Datasource\EntityInterface $user
 * @var array{email: string, name: string|null, is_active: string|null, password: string|null} $fields
 * @var bool $hasActive
 * @var bool $isActive
 * @var bool $isSelf
 * @var \Sso\Model\Entity\SsoIdentity|null $identity
 * @var \Sso\Model\Entity\SsoSessionEvent|null $lastSignIn
 * @var array<\Sso\Model\Entity\SsoSessionEvent> $history
 * @var array<int, string> $actors
 * @var int $retentionDays
 * @var array<array{title: string, element: string, data?: array<string, mixed>}> $sections
 */
use Sso\Model\Entity\SsoSessionEventType;
use Sso\Model\Entity\SsoSignInMethodType;
use Sso\Service\UserProvisioner;

$name = UserProvisioner::displayName($user);
$this->assign('title', $name);
$id = (int)$user->get('id');
$format = fn($time): string => $time === null ? '' : $this->Time->format($time, 'd.M.yyyy HH.mm');
?>
<div class="sso-user" style="max-width: 1200px">
    <p class="mb-2"><?= $this->Html->link('‹ ' . __d('sso', 'Users'), ['plugin' => 'Sso', 'controller' => 'Users', 'action' => 'index']) ?></p>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= h($name) ?></h1>
            <p class="text-body-secondary mb-0"><?= h((string)$user->get($fields['email'])) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?= $this->Form->postButton(
                __d('sso', 'End sessions'),
                ['plugin' => 'Sso', 'controller' => 'Users', 'action' => 'endSessions', $id],
                ['class' => 'btn btn-outline-secondary'],
            ) ?>
            <?php if ($hasActive && $isActive && !$isSelf) : ?>
                <?= $this->Form->postButton(
                    __d('sso', 'Deactivate'),
                    ['plugin' => 'Sso', 'controller' => 'Users', 'action' => 'deactivate', $id],
                    ['class' => 'btn btn-outline-danger', 'confirm' => __d('sso', 'Deactivate {0}? They are signed out of this application and cannot sign in until activated again.', $name)],
                ) ?>
            <?php elseif ($hasActive && !$isActive) : ?>
                <?= $this->Form->postButton(
                    __d('sso', 'Activate'),
                    ['plugin' => 'Sso', 'controller' => 'Users', 'action' => 'activate', $id],
                    ['class' => 'btn btn-outline-primary'],
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold"><?= __d('sso', 'Details') ?></h2>
                    <dl class="row mb-0 small">
                        <?php if ($hasActive) : ?>
                            <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('sso', 'Status') ?></dt>
                            <dd class="col-sm-7"><?= $isActive ? __d('sso', 'Active') : __d('sso', 'Deactivated') ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('sso', 'Rock Software ID') ?></dt>
                        <dd class="col-sm-7"><?= $identity === null
                            ? __d('sso', 'Not yet linked; linked on their first sign-in')
                            : __d('sso', 'Linked') ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('sso', 'Last signed in') ?></dt>
                        <dd class="col-sm-7"><?= $lastSignIn === null
                            ? __d('sso', 'Never')
                            : h($format($lastSignIn->created) . ($lastSignIn->sso_sign_in_method_type === null ? '' : ', ' . SsoSignInMethodType::label($lastSignIn->sso_sign_in_method_type->value))) ?></dd>
                        <?php if ($user->has('created')) : ?>
                            <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('sso', 'Created') ?></dt>
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
            <h2 class="h6 fw-semibold mb-0"><?= __d('sso', 'Sign-in history') ?></h2>
            <span class="small text-body-secondary"><?= __d('sso', 'Kept for {0} days', $retentionDays) ?></span>
        </div>
        <?php if ($history === []) : ?>
            <div class="card-body small text-body-secondary"><?= __d('sso', 'Nothing yet.') ?></div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead>
                        <tr class="text-body-secondary">
                            <th scope="col"><?= __d('sso', 'Time') ?></th>
                            <th scope="col"><?= __d('sso', 'Method') ?></th>
                            <th scope="col"><?= __d('sso', 'Event') ?></th>
                            <th scope="col"><?= __d('sso', 'IP address') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $event) : ?>
                            <?php
                            $type = (string)$event->sso_session_event_type?->value;
                            $refused = in_array($type, SsoSessionEventType::REFUSALS, true);
                            $actor = $event->actor_user_id === null ? '' : ($actors[$event->actor_user_id] ?? __d('sso', 'a removed user'));
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= h($format($event->created)) ?></td>
                                <td><?= $event->sso_sign_in_method_type === null ? '—' : h(SsoSignInMethodType::label($event->sso_sign_in_method_type->value)) ?></td>
                                <td style="color: <?= $refused ? '#a40e26' : ($type === SsoSessionEventType::SIGNED_IN ? '#116329' : 'inherit') ?>"><?= h(SsoSessionEventType::label($type, $actor)) ?></td>
                                <td class="text-body-secondary font-monospace"><?= h((string)$event->ip_address) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
