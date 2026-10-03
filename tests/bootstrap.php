<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\SchemaLoader;
use Cake\Utility\Security;
use Migrations\Migrations;
use Sso\SsoPlugin;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__) . DS . 'tests' . DS . 'test_app');
define('APP_DIR', 'src');
define('APP', ROOT . DS . APP_DIR . DS);
define('TMP', sys_get_temp_dir() . DS . 'cakephp_sso_tests' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CONFIG', ROOT . DS . 'config' . DS);
define('CORE_PATH', dirname(__DIR__) . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp' . DS);
define('CAKE', CORE_PATH . 'src' . DS);

foreach ([LOGS, CACHE] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'base' => false,
    'baseUrl' => false,
    'dir' => APP_DIR,
    'webroot' => 'webroot',
    'wwwRoot' => ROOT . DS . 'webroot' . DS,
    'fullBaseUrl' => 'https://app.example.test',
    'paths' => [
        'plugins' => [ROOT . DS . 'plugins' . DS],
        'templates' => [ROOT . DS . 'templates' . DS],
        'locales' => [ROOT . DS . 'resources' . DS . 'locales' . DS],
    ],
]);
Configure::write('Sso', [
    'loginUrl' => '/users/login',
    'loginRedirect' => '/dashboard',
    'sessionsValidFromField' => 'sessions_valid_from',
]);
Security::setSalt('cakephp-sso-test-salt-0123456789abcdef0123456789abcdef');

mb_internal_encoding('UTF-8');

// A file rather than :memory:, because the migration runner opens its own
// connection and would otherwise migrate a database the tests never see.
$dsn = getenv('DB_DSN');
if ($dsn === false || $dsn === '') {
    $file = TMP . 'test.sqlite';
    if (is_file($file)) {
        unlink($file);
    }
    $dsn = 'sqlite:///' . $file;
}
ConnectionManager::setConfig('test', ['url' => $dsn]);
ConnectionManager::alias('test', 'default');

Cache::setConfig([
    '_cake_translations_' => ['className' => 'Null'],
    '_cake_model_' => ['className' => 'Null'],
    'default' => ['className' => 'Array'],
]);

// The users table stands in for the consuming application's. Its loader drops
// every table first, so it runs before the plugin's own migration, which
// gives the tests the schema an application actually gets.
(new SchemaLoader())->loadInternalFile(__DIR__ . DS . 'schema.php', 'test');
Plugin::getCollection()->add(new SsoPlugin(['path' => dirname(__DIR__) . DS]));
(new Migrations(['connection' => 'test', 'plugin' => 'Sso']))->migrate();
