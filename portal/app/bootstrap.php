<?php
declare(strict_types=1);

/**
 * Application bootstrap: autoloading, config, database, sessions.
 * Included by the front controller (public/index.php) and CLI utilities.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors to the browser in prod

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/helpers.php';

// Minimal PSR-4-ish autoloader for the App\ namespace.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

// Load configuration (config.php created during install from config.sample.php).
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Configuration missing. Copy config/config.sample.php to config/config.php '
        . 'and set your superuser hash (see README / bin/hash.php).';
    exit;
}
/** @var array $config */
$config = require $configFile;
$GLOBALS['config'] = $config;

// Connect + migrate (idempotent) so a fresh deploy is ready on first request.
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');

// Sessions + auth.
App\Auth::boot($config);
