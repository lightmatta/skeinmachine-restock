<?php
declare(strict_types=1);

/**
 * Run (or skip) an automated Sources sync tick, or the legacy periodic stock pull.
 *
 *   php bin/shopify-periodic-sync.php           # honour Allow automated sync / leftover interval
 *   php bin/shopify-periodic-sync.php --force   # pull now even if waiting/off
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This utility runs from the command line only.\n");
}

$root = dirname(__DIR__);
require $root . '/app/helpers.php';
spl_autoload_register(function (string $class) use ($root): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing config/config.php\n");
    exit(1);
}

$config = require $configFile;
App\Database::connect($config);

$force = in_array('--force', array_slice($argv, 1), true);
$result = App\ShopifyService::tickPeriodic($force);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(($result['ok'] ?? false) ? 0 : 1);
