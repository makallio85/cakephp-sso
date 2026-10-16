<?php
/**
 * The plugin's own layout for the sign-in and emergency pages, so an
 * application needs no template of its own. Self-contained: no stylesheet or
 * script from the application or a CDN. An application can name its own layout
 * in `Sso.layout` instead.
 *
 * @var \Cake\View\View $this
 */
use Cake\Core\Configure;

$appName = Configure::read('Sso.appName');
?>
<!DOCTYPE html>
<html lang="<?= h(substr((string)\Cake\I18n\I18n::getLocale(), 0, 2) ?: 'en') ?>">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($this->fetch('title')) ?><?= $appName ? ' · ' . h($appName) : '' ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 16px; background: #eef0ee; color: #1f2a2e;
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .sso-card { width: 100%; max-width: 400px; padding: 32px 28px; background: #fff;
            border: 1px solid #dfe3e1; border-radius: 12px; box-shadow: 0 12px 32px rgba(31, 42, 46, .08); }
        .sso-app { margin: 0 0 4px; font-size: 20px; font-weight: 700; letter-spacing: -.01em; }
        .sso-title { margin: 0 0 24px; color: #55636a; font-size: 15px; }
        .sso-button { display: block; width: 100%; padding: 13px 18px; border: 0; border-radius: 8px;
            background: #1f2a2e; color: #fff; font: inherit; font-weight: 600; text-align: center;
            text-decoration: none; cursor: pointer; }
        .sso-button:hover, .sso-button:focus-visible { background: #3a474c; }
        .sso-button:focus-visible { outline: 2px solid #8f5e4f; outline-offset: 2px; }
        .sso-button-secondary { background: #fff; color: #1f2a2e; border: 1px solid #8a969b; }
        .sso-button-secondary:hover, .sso-button-secondary:focus-visible { background: #f2f4f3; }
        .sso-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; color: #55636a; font-size: 14px; }
        .sso-divider::before, .sso-divider::after { content: ""; flex: 1; height: 1px; background: #dfe3e1; }
        .sso-password-form .input { margin: 0 0 14px; }
        .sso-password-form label { display: block; margin: 0 0 6px; font-size: 15px; font-weight: 500; }
        .sso-password-form input[type=email], .sso-password-form input[type=password] { width: 100%; min-height: 44px;
            padding: 0 12px; border: 1px solid #8a969b; border-radius: 8px; font: inherit; }
        .sso-password-form input:focus-visible { outline: 2px solid #8f5e4f; outline-offset: 1px; }
        .sso-hint { margin: 16px 0 0; color: #55636a; font-size: 14px; }
        .sso-notice, .message { margin: 0 0 16px; padding: 12px 14px; border-radius: 8px;
            background: #fff6e5; border: 1px solid #f0d9a8; font-size: 15px; }
        .message.error { background: #fdecec; border-color: #f3c4c4; }
        .message.success { background: #eaf6ee; border-color: #bfe2c9; }
        code { font-size: 14px; }
    </style>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<main class="sso-card">
    <?php if ($appName) : ?>
        <p class="sso-app"><?= h($appName) ?></p>
    <?php endif; ?>
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</main>
</body>
</html>
