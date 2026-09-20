<?php
declare(strict_types=1);

/**
 * Wholesale product Min floors + apply-button label.
 *
 *   php bin/check-wholesale-min-apply.php
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

$tmp = sys_get_temp_dir() . '/hd-wholesale-min-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Catalog;
use App\Controllers\WholesaleController;
use App\Database;
use App\Settings;
use App\View;

$pdo = Database::pdo();

/* ----- apply button label ----- */
$headerSrc = file_get_contents(APP_ROOT . '/app/Views/layouts/_header.php');
if (!str_contains($headerSrc, 'Apply for a wholesale account')) {
    fail('header CTA should read Apply for a wholesale account');
}
if (str_contains($headerSrc, 'Apply to become a Wholesaler') || str_contains($headerSrc, 'Apply to be a wholesaler')) {
    fail('header still has the old Apply to become a Wholesaler label');
}
$header = View::capture('layouts/_header');
if (!str_contains($header, 'Apply for a wholesale account')) {
    fail('rendered header missing Apply for a wholesale account');
}
$settingsUi = file_get_contents(APP_ROOT . '/app/Views/admin/settings.php');
if (!str_contains($settingsUi, 'Apply for a wholesale account')) {
    fail('settings should quote the new apply button label');
}
$applySrc = file_get_contents(APP_ROOT . '/app/Controllers/PublicController.php');
if (!str_contains($applySrc, "'Apply for a wholesale account'")) {
    fail('apply page title should match the new CTA');
}
pass('Apply button is labeled Apply for a wholesale account');

/* ----- floor math ----- */
$product = ['min_qty' => 10];
if (Settings::wholesaleFloorQty($product, false) !== 10) {
    fail('floor should be products.min_qty, got ' . Settings::wholesaleFloorQty($product, false));
}
if (Settings::wholesaleFloorQty($product, true) !== 1) {
    fail('Ignore min should allow qty 1');
}
if (Settings::wholesaleFloorQty(['min_qty' => 8], false, 6) !== 8) {
    fail('product min should win when it is higher than the bundle min');
}
if (Settings::wholesaleFloorQty(['min_qty' => 8], false, 12) !== 12) {
    fail('bundle min should raise the floor when it is higher than product min');
}
if (Settings::userIgnoresMin(['ignore_min_quantities' => 1]) !== true
    || Settings::userIgnoresMin(['ignore_min_quantities' => 0]) !== false) {
    fail('userIgnoresMin should follow the users.ignore_min_quantities flag');
}
pass('wholesaleFloorQty uses products.min_qty unless Ignore min is set');

/* ----- catalog qty inputs match products.min_qty ----- */
$pdo->prepare("INSERT INTO products (title, price_cents, stock, is_public, status, min_qty)
               VALUES ('Min Yarn', 2000, 20, 1, 'active', 8)")->execute();
$pid = (int)$pdo->lastInsertId();
$product = $pdo->query('SELECT * FROM products WHERE id = ' . $pid)->fetch();

$cards = View::capture('_catalog_cards', [
    'products'  => [$product],
    'bundles'   => [],
    'wholesale' => true,
    'showStock' => false,
    'ignoreMin' => false,
]);
if (!preg_match('/id="qty-' . $pid . '"[^>]*min="8"/', $cards)
    && !preg_match('/min="8"[^>]*id="qty-' . $pid . '"/', $cards)) {
    fail('catalog qty input min should be 8, got: ' . substr($cards, 0, 800));
}
if (!str_contains($cards, 'Min 8')) {
    fail('catalog card should show Min 8');
}

$ignored = View::capture('_catalog_cards', [
    'products'  => [$product],
    'bundles'   => [],
    'wholesale' => true,
    'showStock' => false,
    'ignoreMin' => true,
]);
if (!preg_match('/min="1"/', $ignored) || str_contains($ignored, 'Min 8')) {
    fail('Ignore min clients should get a qty floor of 1');
}
pass('catalog qty inputs use the products.min_qty column');

$pdo->prepare("INSERT INTO bundles (title, is_public) VALUES ('Starter', 1)")->execute();
$bid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty) VALUES (?,?,?)')
    ->execute([$bid, $pid, 2]);
$items = Catalog::bundleItems($bid, true);
if (Catalog::itemFloorQty($items[0], false) !== 8) {
    fail('bundle member floor should be product min 8, not bundle min 2, got ' . Catalog::itemFloorQty($items[0], false));
}
if (Catalog::itemFloorQty($items[0], true) !== 1) {
    fail('Ignore min should drop the bundle member floor to 1');
}
pass('bundle members cannot go below products.min_qty');

/* ----- cart enforceMins ----- */
$cart = WholesaleController::enforceMins(
    ['products' => [$pid => 1], 'bundles' => [$bid => [$pid => 2]]],
    false
);
if ((int)$cart['products'][$pid] !== 8) {
    fail('cart product qty 1 should clamp to min 8, got ' . json_encode($cart));
}
if ((int)$cart['bundles'][$bid][$pid] !== 8) {
    fail('cart bundle qty 2 should clamp to product min 8, got ' . json_encode($cart));
}
$free = WholesaleController::enforceMins(
    ['products' => [$pid => 1], 'bundles' => [$bid => [$pid => 2]]],
    true
);
if ((int)$free['products'][$pid] !== 1 || (int)$free['bundles'][$bid][$pid] !== 2) {
    fail('Ignore min should leave sub-min qty alone, got ' . json_encode($free));
}
pass('cart quantities clamp to products.min_qty unless Ignore min is set');

$cartSrc = file_get_contents(APP_ROOT . '/app/Views/wholesale/cart.php');
if (!str_contains($cartSrc, "min=\"<?= (int)(\$l['min'] ?? 1) ?>\"")
    && !str_contains($cartSrc, "min=\"<?= (int)(\$l['min']")) {
    fail('cart qty inputs should use the per-line min');
}
if (!str_contains(file_get_contents(APP_ROOT . '/public/assets/app.js'), 'clampQty')) {
    fail('client script should clamp qty inputs to min');
}
pass('cart and add-to-cart UIs bind the product Min floor');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
