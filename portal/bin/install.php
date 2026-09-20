<?php
declare(strict_types=1);

/**
 * Installer / migrator.
 *
 *   php portal/bin/install.php            # create config.php (if missing) + migrate DB
 *   php portal/bin/install.php --seed     # also load demo products/bundles/users
 *   php portal/bin/install.php --fresh    # delete the SQLite DB first, then migrate
 *
 * On first run this copies config.sample.php to config.php if it does not
 * exist. Edit config.php afterwards to set your real superuser hash.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This utility runs from the command line only.\n");
}

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$seed  = in_array('--seed', $args, true);
$fresh = in_array('--fresh', $args, true);

$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    copy($root . '/config/config.sample.php', $configFile);
    echo "Created config/config.php from the sample. Edit it to set your superuser hash.\n";
}

require $root . '/app/helpers.php';
spl_autoload_register(function (string $class) use ($root): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});

$config = require $configFile;

if ($fresh && is_file($config['db']['path'])) {
    foreach ([$config['db']['path'], $config['db']['path'] . '-wal', $config['db']['path'] . '-shm'] as $f) {
        if (is_file($f)) unlink($f);
    }
    echo "Removed existing database.\n";
}

App\Database::connect($config);
App\Database::migrate($root . '/migrations/schema.sql');
echo "Database migrated.\n";

if ($seed) {
    require $root . '/migrations/seed.php';
    echo "Demo data seeded.\n";
}

echo "Install complete.\n";
