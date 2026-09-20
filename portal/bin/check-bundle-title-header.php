<?php
declare(strict_types=1);

/**
 * Bundle name editing, bundle-view image width, and header alignment.
 *
 *   php bin/check-bundle-title-header.php
 */

error_reporting(E_ALL);

$appRoot = dirname(__DIR__);
define('APP_ROOT', $appRoot);

require APP_ROOT . '/app/helpers.php';

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

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function pass(string $msg): void
{
    echo "ok  {$msg}\n";
}

$tmp = sys_get_temp_dir() . '/hd-bth-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Catalog;
use App\Database;
use App\View;

$pdo = Database::pdo();

$raw = 'https://cdn.shopify.com/s/files/1/x/files/yarn.jpg?v=1';
$pdo->prepare("INSERT INTO products (sku, title, image_url, price_cents, stock, is_public, status)
               VALUES ('SKU-1','Coral Reef',?,2400,12,1,'active')")->execute([$raw]);
$pid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO bundles (title, description, is_public) VALUES ('Beginner Bundle','Starter set',1)")->execute();
$bid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty) VALUES (?,?,1)')->execute([$bid, $pid]);

/* ----- admin can edit bundle name ----- */
$ui = View::capture('admin/bundles', [
    'active' => 'bundles',
    'readonly' => false,
    'bundles' => $pdo->query('SELECT * FROM bundles')->fetchAll(),
    'byBundle' => [$bid => [['id' => 1, 'title' => 'Coral Reef', 'price_cents' => 2400, 'min_qty' => 1]]],
    'products' => [['id' => $pid, 'title' => 'Coral Reef', 'price_cents' => 2400]],
]);
if (!str_contains($ui, 'class="bundle-title"') || !str_contains($ui, 'Beginner Bundle')) {
    fail('admin bundles page should expose an editable bundle name');
}
$src = file_get_contents(APP_ROOT . '/app/Views/admin/bundles.php');
if (!str_contains($src, 'class="bundle-title"') || !str_contains($src, "changes:{title:title}")) {
    fail('bundle name edits should save through the bundles update API');
}
$staffUi = View::capture('admin/bundles', [
    'active' => 'bundles',
    'readonly' => true,
    'bundles' => $pdo->query('SELECT * FROM bundles')->fetchAll(),
    'byBundle' => [$bid => []],
    'products' => [],
]);
if (str_contains($staffUi, 'class="bundle-title"')) {
    fail('staff should not get an editable bundle name field');
}
$ctrl = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($ctrl, "'title'") || !str_contains($ctrl, 'Bundle name cannot be empty')) {
    fail('admin API should whitelist and validate bundle titles');
}
$pdo->prepare('UPDATE bundles SET title = ? WHERE id = ?')->execute(['House Starter Kit', $bid]);
$got = (string)$pdo->query('SELECT title FROM bundles WHERE id = ' . $bid)->fetchColumn();
if ($got !== 'House Starter Kit') {
    fail('bundle title should persist, got ' . $got);
}
pass('admins can edit bundle names; staff cannot');

/* ----- bundle view images are width=400 ----- */
$bundleSrc = file_get_contents(APP_ROOT . '/app/Views/public/bundle.php');
if (!str_contains($bundleSrc, "catalog_thumb(\$it, 'yarn', 400)")) {
    fail('bundle view thumbs should request width=400');
}
if (str_contains($bundleSrc, '800')) {
    fail('bundle view should not request width=800 images');
}
$product = $pdo->query('SELECT * FROM products WHERE id = ' . $pid)->fetch();
$thumb = catalog_thumb($product, 'yarn', 400);
if (!str_contains($thumb, 'width=400') || str_contains($thumb, 'width=800')) {
    fail('bundle member thumb should be width=400, got ' . $thumb);
}
$found = Catalog::findBundle($bid, true);
$memberThumb = catalog_thumb($found['items'][0], 'yarn', 400);
if (!str_contains($memberThumb, 'width=400')) {
    fail('live bundle member image should request width=400');
}
pass('bundle view images use width=400');

/* ----- header brand aligns with body; menus sit between brand and login ----- */
$headerSrc = file_get_contents(APP_ROOT . '/app/Views/layouts/_header.php');
$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($headerSrc, 'topbar-inner') || !str_contains($headerSrc, 'nav-cluster')) {
    fail('header should wrap brand/nav/actions in a content-aligned inner bar');
}
if (!str_contains($css, '--page-max: 1100px') || !str_contains($css, '--page-gutter: 22px')) {
    fail('header and body should share the same content width tokens');
}
if (!str_contains($css, 'max-width: var(--page-max)') || !str_contains($css, '.topbar-inner')) {
    fail('topbar inner should use the same max-width as .container');
}
if (!str_contains($css, 'grid-template-columns: auto minmax(0, 1fr) auto')) {
    fail('header grid should leave a flexible middle for centred menus');
}
if (preg_match('/\.nav-center\s+\.nav-cta\s*\{[^}]*position:\s*absolute/s', $css)) {
    fail('Apply CTA should sit in the centred nav cluster, not be absolutely offset');
}
if (str_contains($css, 'left: 100%')) {
    fail('nav CTA should not be pulled out of the centred cluster with left: 100%');
}
$header = View::capture('layouts/_header');
if (!str_contains($header, 'topbar-inner') || !str_contains($header, 'nav-cluster')) {
    fail('rendered header missing alignment wrappers');
}
if (!str_contains($header, 'HouseDye Yarns') && !str_contains($header, 'brand-name')) {
    fail('company name should render in the brand');
}
pass('header brand aligns with body content and menus sit between brand and login');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
