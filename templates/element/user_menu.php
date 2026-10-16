<?php
/**
 * The signed-in person's menu as a dropdown: `<?= $this->element('Sso.user_menu') ?>`
 * in the application's layout. No script and no stylesheet of the
 * application's; colours come from CSS variables the application may set:
 * `--sso-menu-accent`, `--sso-menu-surface`, `--sso-menu-text`,
 * `--sso-menu-muted`, `--sso-menu-border`.
 *
 * An application that renders its own menu uses `$this->Sso->menuItems()`.
 *
 * @var \Cake\View\View $this
 */
$this->loadHelper('Sso.Sso');
$items = $this->Sso->menuItems();
if ($items === []) {
    return;
}
$header = $items[0];
$apps = array_filter($items, fn(array $item): bool => $item['type'] === 'app');
$initials = '';
foreach (preg_split('/[\s@.]+/u', (string)$header['label'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
    $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    if (mb_strlen($initials) === 2) {
        break;
    }
}
?>
<details class="sso-user-menu">
    <style>
        .sso-user-menu { position: relative; display: inline-block; font: inherit;
            --sso-menu-accent-default: #0b5cad; --sso-menu-surface-default: #fff; --sso-menu-text-default: #1f2328;
            --sso-menu-muted-default: #57606a; --sso-menu-border-default: #d0d7de; }
        .sso-user-menu > summary { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 10px;
            list-style: none; cursor: pointer; border-radius: 6px; color: var(--sso-menu-text, var(--sso-menu-text-default));
            border: 1px solid var(--sso-menu-border, var(--sso-menu-border-default)); background: var(--sso-menu-surface, var(--sso-menu-surface-default)); }
        .sso-user-menu > summary::-webkit-details-marker { display: none; }
        .sso-user-menu > summary:focus-visible { outline: 2px solid var(--sso-menu-accent, var(--sso-menu-accent-default)); outline-offset: 2px; }
        .sso-user-menu .sso-avatar { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center;
            justify-content: center; font-size: 12px; font-weight: 600; color: #fff; background: var(--sso-menu-accent, var(--sso-menu-accent-default)); }
        .sso-user-menu .sso-panel { position: absolute; right: 0; top: calc(100% + 6px); z-index: 1050; width: min(320px, calc(100vw - 32px));
            background: var(--sso-menu-surface, var(--sso-menu-surface-default)); color: var(--sso-menu-text, var(--sso-menu-text-default));
            border: 1px solid var(--sso-menu-border, var(--sso-menu-border-default)); border-radius: 8px; box-shadow: 0 8px 24px rgba(31, 35, 40, .15); }
        .sso-user-menu .sso-head { padding: 14px 16px; border-bottom: 1px solid var(--sso-menu-border, var(--sso-menu-border-default)); }
        .sso-user-menu .sso-head strong { display: block; font-weight: 600; }
        .sso-user-menu .sso-muted { font-size: 13px; color: var(--sso-menu-muted, var(--sso-menu-muted-default)); }
        .sso-user-menu .sso-group { padding: 8px; border-bottom: 1px solid var(--sso-menu-border, var(--sso-menu-border-default)); }
        .sso-user-menu .sso-group:last-child { border-bottom: 0; }
        .sso-user-menu .sso-group-title { display: block; padding: 4px 8px; font-size: 12px; font-weight: 600; }
        .sso-user-menu .sso-item { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 8px; border-radius: 6px;
            color: inherit; text-decoration: none; }
        .sso-user-menu .sso-item:hover, .sso-user-menu .sso-item:focus-visible { background: rgba(127, 127, 127, .12); }
        .sso-user-menu .sso-item[aria-current] { font-weight: 600; }
        .sso-user-menu .sso-item .sso-muted { margin-left: auto; }
    </style>
    <summary aria-label="<?= h(__d('sso', 'Menu of {0}', $header['label'])) ?>">
        <span class="sso-avatar" aria-hidden="true"><?= h($initials) ?></span>
        <span class="sso-name"><?= h($header['label']) ?></span>
    </summary>
    <div class="sso-panel">
        <div class="sso-head">
            <strong><?= h($header['label']) ?></strong>
            <span class="sso-muted"><?= h($header['detail']) ?></span>
        </div>
        <?php if ($apps !== []) : ?>
            <nav class="sso-group" aria-label="<?= h(__d('sso', 'Applications')) ?>">
                <span class="sso-group-title sso-muted"><?= __d('sso', 'Applications') ?></span>
                <?php foreach ($apps as $app) : ?>
                    <a class="sso-item" href="<?= h($app['url']) ?>"<?= $app['current'] ? ' aria-current="page"' : '' ?>>
                        <?= h($app['label']) ?>
                        <?php if ($app['current']) : ?><span class="sso-muted"><?= __d('sso', 'this one') ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="sso-group">
            <?php foreach ($items as $item) : ?>
                <?php if ($item['type'] === 'link') : ?>
                    <a class="sso-item" href="<?= h($item['url']) ?>"><?= h($item['label']) ?></a>
                <?php elseif ($item['type'] === 'logout') : ?>
                    <?= $this->Form->postLink($item['label'], $item['url'], ['class' => 'sso-item']) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</details>
