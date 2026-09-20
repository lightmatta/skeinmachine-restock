<?php
declare(strict_types=1);

/**
 * Isolated checks for bundle delete/visibility, catalog descriptions,
 * wholesale add-to-cart cards, and the stock-levels setting.
 *
 *   php bin/check-bundle-catalog.php
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

$tmp = sys_get_temp_dir() . '/hd-bundle-check-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');

use App\Catalog;
use App\Database;
use App\Settings;
use App\View;

$pdo = Database::pdo();

$pdo->prepare("INSERT INTO products (sku, title, description, category, price_cents, stock, is_public, status)
               VALUES ('SKU-1','Coral Reef','Superwash merino nylon fingering one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone twentytwo.','Fingering',2400,12,1,'active')")
    ->execute();
$pid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO bundles (title, description, is_public) VALUES ('Beginner Bundle','A curated starter set for new stockists with extra words so the listing will truncate past twenty words on the card.',1)")
    ->execute();
$bid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty) VALUES (?,?,1)')->execute([$bid, $pid]);

$pdo->prepare("INSERT INTO users (email, role, status) VALUES ('shop@example.com','wholesale','active')")->execute();
$uid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'completed', 'paid', 2400)")->execute([$uid]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, bundle_id, title, qty, unit_price_cents, line_total_cents)
               VALUES (?,?,?,?,?,?,?)')->execute([$oid, $pid, $bid, '[Beginner Bundle] Coral Reef', 1, 2400, 2400]);

/* ----- descriptions ----- */
$desc = catalog_description('one two three four five');
if (!str_contains($desc, 'one two three four five') || str_contains($desc, 'catalog-more')) {
    fail('short descriptions should render in full without a more link');
}
$words = [];
for ($i = 1; $i <= 25; $i++) {
    $words[] = 'w' . $i;
}
$long = implode(' ', $words);
$html = catalog_description($long);
if (!str_contains($html, 'catalog-more') || !str_contains($html, '...more')) {
    fail('long descriptions need a ...more toggle');
}
if (!preg_match('/catalog-desc-body">([^<]+)</', $html, $m)) {
    fail('long description missing truncated body');
}
if (str_contains($m[1], 'w21')) {
    fail('collapsed description leaked words after the 20-word limit: ' . $m[1]);
}
if (!str_contains($html, 'data-full="' . e($long) . '"')) {
    fail('full description should be available for the toggle');
}
pass('catalog descriptions truncate at 20 words with a ...more link');

/* ----- public / hidden ----- */
$listed = Catalog::bundles(true);
if (count($listed) !== 1) {
    fail('public bundle should appear for wholesale, got ' . count($listed));
}
$pdo->prepare('UPDATE bundles SET is_public = 0 WHERE id = ?')->execute([$bid]);
if (Catalog::bundles(true) || Catalog::bundles(false) || Catalog::findBundle($bid, true) || Catalog::findBundle($bid, false)) {
    fail('hidden bundle still appears on a storefront');
}
$invoice = $pdo->query('SELECT * FROM order_items WHERE order_id = ' . $oid)->fetch();
if (!$invoice || $invoice['title'] !== '[Beginner Bundle] Coral Reef' || (int)$invoice['line_total_cents'] !== 2400) {
    fail('hiding a bundle changed the historical invoice');
}
pass('hidden bundles leave the catalog and keep invoice snapshots');

$pdo->prepare('UPDATE bundles SET is_public = 1 WHERE id = ?')->execute([$bid]);
if (!Catalog::findBundle($bid, true)) {
    fail('making a bundle public should restore it to the catalog');
}
pass('public toggle restores a bundle to the catalog');

/* ----- delete preserves invoices ----- */
$src = (string)file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($src, "if (\$entity === 'bundles')") || !str_contains($src, "empty(\$body['confirm'])")) {
    fail('bundle delete must require an explicit confirm flag');
}
$ui = (string)file_get_contents(APP_ROOT . '/app/Views/admin/bundles.php');
if (!str_contains($ui, 'deleteBundleModal') || !str_contains($ui, 'I understand this cannot be undone') || !str_contains($ui, 'toggle-public')) {
    fail('admin bundles page must offer delete confirmation and a public/hidden toggle');
}

Catalog::deleteBundle($bid);
if ($pdo->query('SELECT COUNT(*) FROM bundles WHERE id = ' . $bid)->fetchColumn()) {
    fail('bundle row remained after delete');
}
$invoice = $pdo->query('SELECT * FROM order_items WHERE order_id = ' . $oid)->fetch();
if (!$invoice) {
    fail('order line was removed when the bundle was deleted');
}
if ($invoice['title'] !== '[Beginner Bundle] Coral Reef' || (int)$invoice['line_total_cents'] !== 2400) {
    fail('deleting a bundle changed the historical invoice text/price');
}
if ($invoice['bundle_id'] !== null && $invoice['bundle_id'] !== '') {
    fail('deleted bundle should detach from order_items.bundle_id, still have ' . json_encode($invoice['bundle_id']));
}
pass('deleting a bundle keeps historical invoice lines');

/* ----- wholesale cards vs guest ----- */
$product = $pdo->query('SELECT * FROM products WHERE id = ' . $pid)->fetch();
Settings::set('wholesale_show_stock', '0');
$cards = View::capture('_catalog_cards', [
    'products'  => [$product],
    'bundles'   => [],
    'wholesale' => true,
    'showStock' => false,
]);
if (!str_contains($cards, 'Add to cart')) {
    fail('wholesale cards should show Add to cart');
}
if (str_contains($cards, 'Sign in as a wholesale client')) {
    fail('wholesale cards still show the guest sign-in prompt');
}
if (str_contains($cards, 'in stock')) {
    fail('stock badge showed while the setting is off');
}
if (!str_contains($cards, '...more')) {
    fail('product card should truncate the long description');
}
pass('wholesale cards show add-to-cart, truncated copy, and no stock when disabled');

$cardsOn = View::capture('_catalog_cards', [
    'products'  => [$product],
    'bundles'   => [],
    'wholesale' => true,
    'showStock' => true,
]);
if (!str_contains($cardsOn, '12 in stock')) {
    fail('stock badge missing when the setting is on');
}
pass('stock badge appears only when the setting is on');

$guest = View::capture('_catalog_cards', [
    'products'  => [$product],
    'bundles'   => [],
    'wholesale' => false,
    'showStock' => false,
]);
if (!str_contains($guest, 'Sign in as a wholesale client')) {
    fail('guest cards should keep the sign-in prompt');
}
if (str_contains($guest, 'Add to cart')) {
    fail('guest cards should not offer add to cart');
}
pass('guest cards keep the sign-in prompt');

$settingsUi = (string)file_get_contents(APP_ROOT . '/app/Views/admin/settings.php');
$usersUi = (string)file_get_contents(APP_ROOT . '/app/Views/admin/users.php');
if (!str_contains($settingsUi, 'wholesale_show_stock')) {
    fail('stock-levels checkbox missing from admin settings');
}
if (str_contains($usersUi, 'wholesaleShowStock') || str_contains($usersUi, 'Wholesale client views')) {
    fail('Users page should not duplicate the wholesale client views setting');
}
if (Settings::get('wholesale_show_stock', '0') !== '0' && Settings::get('wholesale_show_stock') !== '1') {
    fail('wholesale_show_stock setting was not stored');
}
pass('admin settings expose the stock-levels checkbox; users page does not duplicate it');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "bundle and catalog checks passed\n";
