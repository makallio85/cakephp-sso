<?php
/**
 * Placeholder front-controller for the cakephp-sso dev sandbox.
 *
 * This repo is a plugin (library) — there is no host app. The container
 * exists so agents and humans can exec in to develop and test the plugin
 * against a live DB + Redis. nginx still needs *something* to serve so
 * Coolify's HTTP health check returns 200; this is that something.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'plugin'   => 'makallio85/cakephp-sso',
    'sandbox'  => true,
    'message'  => 'Plugin dev container is alive. This URL serves no app — exec into the container to develop.',
    'php'      => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
