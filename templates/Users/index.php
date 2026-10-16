<?php
/**
 * The users page. Bootstrap 5 markup; an application can restyle it by
 * overriding `templates/plugin/RockSoftware/Users/Users/index.php`.
 *
 * @var \Cake\View\View $this
 * @var iterable<\Cake\Datasource\EntityInterface> $users
 * @var array{email: string, name: string|null, is_active: string|null, password: string|null} $fields
 * @var bool $hasActive
 * @var string $search
 * @var string $status
 * @var array<int, bool> $linked
 * @var array<int, \RockSoftware\Users\Model\Entity\SessionEvent> $lastSignIns
 * @var array<array{label: string, value: callable}> $columns
 */
use RockSoftware\Users\Model\Entity\SignInMethodType;
use RockSoftware\Users\Service\UserProvisioner;

$this->assign('title', __d('rock_software_users', 'Users'));
$rows = [];
foreach ($users as $user) {
    $id = (int)$user->get('id');
    $last = $lastSignIns[$id] ?? null;
    $active = UserProvisioner::isActive($user);
    $rows[] = [
        'id' => $id,
        'name' => UserProvisioner::displayName($user),
        'email' => (string)$user->get($fields['email']),
        'linked' => isset($linked[$id]),
        'last' => $last === null ? __d('rock_software_users', 'Never') : $this->Time->format($last->created, 'd.M.yyyy HH.mm'),
        'method' => $last?->sign_in_method_type === null ? '' : SignInMethodType::label($last->sign_in_method_type->value),
        'active' => $active,
        'extra' => array_map(fn(array $column): string => (string)($column['value'])($user), $columns),
    ];
}
$statusBadge = fn(bool $active): string => $active
    ? '<span class="badge rounded-pill" style="background:#dafbe1;color:#116329">' . __d('rock_software_users', 'Active') . '</span>'
    : '<span class="badge rounded-pill" style="background:#eaeef2;color:#424a53">' . __d('rock_software_users', 'Deactivated') . '</span>';
?>
<div class="rsu-users" style="max-width: 1200px">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= __d('rock_software_users', 'Users') ?></h1>
            <p class="text-body-secondary mb-0"><?= __d('rock_software_users', 'Access to the application and new users are granted in Rock Software ID.') ?></p>
        </div>
        <form method="get" action="<?= h($this->Url->build(['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'index'])) ?>" class="d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small text-body-secondary mb-1" for="rsu-users-q"><?= __d('rock_software_users', 'Search') ?></label>
                <input type="search" class="form-control" id="rsu-users-q" name="q" value="<?= h($search) ?>" placeholder="<?= h(__d('rock_software_users', 'Name or email address')) ?>">
            </div>
            <?php if ($hasActive) : ?>
                <div>
                    <label class="form-label small text-body-secondary mb-1" for="rsu-users-status"><?= __d('rock_software_users', 'Status') ?></label>
                    <select class="form-select" id="rsu-users-status" name="status">
                        <?php foreach (['active' => __d('rock_software_users', 'Active'), 'inactive' => __d('rock_software_users', 'Deactivated'), 'all' => __d('rock_software_users', 'All')] as $value => $label) : ?>
                            <option value="<?= $value ?>"<?= $status === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-outline-secondary"><?= __d('rock_software_users', 'Filter') ?></button>
        </form>
    </div>

    <?php if ($rows === []) : ?>
        <p class="text-body-secondary"><?= __d('rock_software_users', 'No users match.') ?></p>
    <?php else : ?>
        <div class="card border-0 shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="small text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Name') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Email address') ?></th>
                            <?php foreach ($columns as $column) : ?>
                                <th scope="col"><?= h($column['label']) ?></th>
                            <?php endforeach; ?>
                            <th scope="col"><?= __d('rock_software_users', 'Rock Software ID') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Last signed in') ?></th>
                            <?php if ($hasActive) : ?><th scope="col"><?= __d('rock_software_users', 'Status') ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?= $this->Html->link($row['name'], ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'view', $row['id']], ['class' => 'fw-medium']) ?></td>
                                <td class="text-body-secondary"><?= h($row['email']) ?></td>
                                <?php foreach ($row['extra'] as $value) : ?>
                                    <td><?= h($value) ?></td>
                                <?php endforeach; ?>
                                <td><?= $row['linked'] ? __d('rock_software_users', 'Linked') : __d('rock_software_users', 'Not yet') ?></td>
                                <td><?= h($row['last']) ?><?php if ($row['method'] !== '') : ?><br><span class="small text-body-secondary"><?= h($row['method']) ?></span><?php endif; ?></td>
                                <?php if ($hasActive) : ?><td><?= $statusBadge($row['active']) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <ul class="list-unstyled d-md-none d-flex flex-column gap-2 mb-0">
            <?php foreach ($rows as $row) : ?>
                <li>
                    <a class="card border shadow-sm text-reset text-decoration-none p-3 d-flex flex-column gap-1" href="<?= h($this->Url->build(['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'view', $row['id']])) ?>">
                        <span class="d-flex justify-content-between gap-2">
                            <strong class="fw-semibold"><?= h($row['name']) ?></strong>
                            <?php if ($hasActive && !$row['active']) : ?><?= $statusBadge(false) ?><?php endif; ?>
                        </span>
                        <span class="small text-body-secondary"><?= h($row['email']) ?></span>
                        <?php foreach ($row['extra'] as $i => $value) : ?>
                            <span class="small"><?= h($columns[$i]['label']) ?>: <?= h($value) ?></span>
                        <?php endforeach; ?>
                        <span class="small"><?= h($row['last']) ?><?= $row['method'] !== '' ? ' · ' . h($row['method']) : '' ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($this->Paginator->hasPage(2)) : ?>
            <nav class="mt-3" aria-label="<?= h(__d('rock_software_users', 'Pages')) ?>">
                <ul class="pagination flex-wrap mb-0">
                    <?= $this->Paginator->prev('‹') ?>
                    <?= $this->Paginator->numbers() ?>
                    <?= $this->Paginator->next('›') ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
