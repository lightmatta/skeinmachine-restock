<?php
declare(strict_types=1);

/**
 * Isolated checks for Periodic Sync, per-user grid heading prefs, and
 * service-worker system notifications.
 *
 *   php bin/check-periodic-sync-prefs.php
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

$tmp = sys_get_temp_dir() . '/hd-periodic-prefs-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Auth;
use App\Database;
use App\Settings;
use App\ShopifyService;
use App\UserPrefs;
use App\View;

$pdo = Database::pdo();

/* ----- defaults + settings UI ----- */
if (Settings::get('shopify_periodic_sync', '0') !== '0') {
    fail('Periodic Sync should default off');
}
if (Settings::get('shopify_periodic_minutes', '60') !== '60') {
    fail('Periodic Sync interval should default to 60 minutes');
}
$setUi = View::capture('admin/settings', ['active' => 'settings', 'settings' => Settings::all(), 'saved' => false]);
if (!str_contains($setUi, 'Periodic Sync') || !str_contains($setUi, 'name="shopify_periodic_sync"')) {
    fail('settings missing Periodic Sync checkbox');
}
if (!str_contains($setUi, 'name="shopify_periodic_minutes"') || !str_contains($setUi, 'Sync interval (minutes)')) {
    fail('settings missing interval-in-minutes field');
}
$adminSrc = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, 'shopify_periodic_sync') || !str_contains($adminSrc, 'schedulePeriodicFromNow')) {
    fail('settings save should persist Periodic Sync and restart the interval clock');
}
$alertsSrc = file_get_contents(APP_ROOT . '/app/Controllers/MessageController.php');
if (!str_contains($alertsSrc, 'tickPeriodic')) {
    fail('chat.alerts should tick Periodic Sync so the portal can pull stock without cron');
}
$cli = file_get_contents(APP_ROOT . '/bin/shopify-periodic-sync.php');
if ($cli === false || !str_contains($cli, 'tickPeriodic')) {
    fail('CLI periodic sync helper is missing');
}
pass('Periodic Sync defaults to off / 60 minutes and is exposed in Settings');

/* ----- schedule + skip while waiting ----- */
Settings::set('shopify_periodic_sync', '1');
Settings::set('shopify_periodic_minutes', '60');
ShopifyService::schedulePeriodicFromNow();
$next = (int)Settings::get('shopify_periodic_next_at', '0');
if ($next < time() + 55 * 60 || $next > time() + 65 * 60) {
    fail('saving Periodic Sync should schedule ~60 minutes from now, got ' . $next);
}
$tick = ShopifyService::tickPeriodic();
if (($tick['ran'] ?? true) !== false || ($tick['reason'] ?? '') !== 'waiting') {
    fail('tick should wait until the interval elapses, got ' . json_encode($tick));
}
Settings::set('shopify_periodic_minutes', '10');
ShopifyService::schedulePeriodicFromNow();
$next10 = (int)Settings::get('shopify_periodic_next_at', '0');
if ($next10 < time() + 8 * 60 || $next10 > time() + 12 * 60) {
    fail('changing the interval should restart the clock from now (~10 min), got ' . $next10);
}
Settings::set('shopify_periodic_sync', '0');
ShopifyService::schedulePeriodicFromNow();
if (Settings::get('shopify_periodic_next_at', '1') !== '0') {
    fail('turning Periodic Sync off should clear the next-run time');
}
$off = ShopifyService::tickPeriodic();
if (($off['reason'] ?? '') !== 'off') {
    fail('tick should no-op when the checkbox is off, got ' . json_encode($off));
}
pass('interval clock restarts on save and stops when Periodic Sync is off');

/* ----- stock-only pull updates matching products ----- */
$pdo->exec("INSERT INTO products (sku, title, description, price_cents, stock, shopify_product_id, status, is_public)
            VALUES ('Y1','Keep Title','Keep desc', 2400, 1, '99', 'active', 1)");
$keepId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO products (sku, title, price_cents, stock, shopify_product_id, status, is_public)
            VALUES ('LOCAL','Local Only', 500, 9, NULL, 'active', 1)");
$localId = (int)$pdo->lastInsertId();

$calls = [];
ShopifyService::$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = ['method' => $method, 'url' => $url];
    if (str_contains($url, '/admin/oauth/access_token')) {
        return [200, json_encode(['access_token' => 'tok_stock', 'expires_in' => 86399])];
    }
    if (str_contains($url, 'products.json')) {
        return [200, json_encode(['products' => [[
            'id' => 99,
            'title' => 'Shopify Renamed',
            'body_html' => '<p>changed</p>',
            'product_type' => 'Fingering',
            'image' => ['src' => 'https://cdn.shopify.com/s/files/x.jpg'],
            'images' => [],
            'variants' => [['price' => '99.00', 'sku' => 'Y1', 'inventory_quantity' => 12]],
        ], [
            'id' => 100,
            'title' => 'Brand New From Shopify',
            'body_html' => '',
            'product_type' => '',
            'variants' => [['price' => '10.00', 'sku' => 'NEW', 'inventory_quantity' => 3]],
        ]]])];
    }
    return [404, '{}'];
};

Settings::set('shopify_domain', 'house-dye.myshopify.com');
Settings::set('shopify_client_id', 'cid-123');
Settings::set('shopify_client_secret', 'csec-456');
Settings::set('shopify_periodic_sync', '1');
Settings::set('shopify_periodic_minutes', '60');
Settings::set('shopify_periodic_next_at', (string)(time() - 5));

$ran = ShopifyService::tickPeriodic();
if (empty($ran['ok']) || empty($ran['ran']) || (int)($ran['updated'] ?? 0) !== 1) {
    fail('due Periodic Sync should update stock for matching Shopify IDs, got ' . json_encode($ran));
}
$row = $pdo->query('SELECT title, description, price_cents, stock FROM products WHERE id = ' . $keepId)->fetch();
if ((int)$row['stock'] !== 12) {
    fail('stock should update to Shopify inventory (12), got ' . json_encode($row));
}
if ($row['title'] !== 'Keep Title' || (int)$row['price_cents'] !== 2400 || $row['description'] !== 'Keep desc') {
    fail('periodic stock sync must not change title/price/description, got ' . json_encode($row));
}
$local = $pdo->query('SELECT stock FROM products WHERE id = ' . $localId)->fetch();
if ((int)$local['stock'] !== 9) {
    fail('products without a Shopify id should be left alone');
}
$created = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE shopify_product_id = '100'")->fetchColumn();
if ($created !== 0) {
    fail('periodic stock sync must not create catalog rows');
}
$after = (int)Settings::get('shopify_periodic_next_at', '0');
if ($after < time() + 50 * 60) {
    fail('a completed run should schedule the next interval from now');
}
if (Settings::get('shopify_last_stock_sync', '') === '') {
    fail('last stock sync timestamp should be stored');
}
ShopifyService::$transport = null;
pass('Periodic Sync pulls stock for the current collection and writes it onto Products');

/* ----- per-user heading visibility ----- */
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_prefs'")->fetchColumn();
if ($tables !== 'user_prefs') {
    fail('user_prefs table should exist');
}
if (UserPrefs::hiddenColumns(1, UserPrefs::GRID_PRODUCTS, ['description', 'shopify_product_id'])
    !== ['description', 'shopify_product_id']) {
    fail('missing product prefs should use the default hidden columns');
}
UserPrefs::set(1, UserPrefs::GRID_PRODUCTS, ['sku', 'description']);
UserPrefs::set(2, UserPrefs::GRID_PRODUCTS, ['stock']);
UserPrefs::set(0, UserPrefs::GRID_ORDERS, ['notes', 'admin_notes']);
UserPrefs::set(1, UserPrefs::GRID_USERS, []);
if (UserPrefs::hiddenColumns(1, UserPrefs::GRID_PRODUCTS, ['description']) !== ['sku', 'description']) {
    fail('admin 1 product headings should persist independently');
}
if (UserPrefs::hiddenColumns(2, UserPrefs::GRID_PRODUCTS, ['description']) !== ['stock']) {
    fail('admin 2 product headings should not leak from admin 1');
}
if (UserPrefs::hiddenColumns(0, UserPrefs::GRID_ORDERS, ['notes']) !== ['notes', 'admin_notes']) {
    fail('superuser (id 0) should be able to store order heading prefs');
}
if (UserPrefs::hiddenColumns(1, UserPrefs::GRID_USERS, ['password']) !== []) {
    fail('an explicit empty list means show every Users column');
}
$prodSrc = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
$ordSrc = file_get_contents(APP_ROOT . '/app/Views/admin/orders.php');
$userSrc = file_get_contents(APP_ROOT . '/app/Views/admin/users.php');
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($prodSrc, 'persistHidden') || !str_contains($ordSrc, 'persistHidden') || !str_contains($userSrc, 'persistHidden')) {
    fail('Products, Orders and Users grids should persist heading visibility');
}
if (!str_contains($js, 'persistHidden') || !str_contains($js, 'saveHidden') || !str_contains($js, 'entity: "prefs"')) {
    fail('DataGrid should POST hidden columns to admin/api prefs');
}
if (!str_contains($adminSrc, 'prefsApi') || (!str_contains($adminSrc, 'GRID_KEYS') && !str_contains($adminSrc, 'PREF_KEYS'))) {
    fail('admin API should accept per-user grid heading prefs');
}
pass('heading visibility is stored per user for Products, Orders and Users');

/* ----- mobile / OS notifications via service worker ----- */
$sw = file_get_contents(APP_ROOT . '/public/sw.js');
$layout = file_get_contents(APP_ROOT . '/app/Views/layouts/app.php');
if (!str_contains($js, 'showNotification') || !str_contains($js, 'function browserNotify')) {
    fail('client should launch notifications through registration.showNotification');
}
if (!str_contains($sw, 'showNotification') || !str_contains($sw, 'notificationclick') || !str_contains($sw, 'vibrate')) {
    fail('service worker should display OS notifications and handle clicks');
}
if (!str_contains($js, 'notifyPrompt') || !str_contains($layout, 'id="notifyPrompt"') || !str_contains($layout, '__NOTIFY_WANTED__')) {
    fail('logged-in users should get a gesture-based permission prompt for phone notifications');
}
if (!str_contains($js, 'armPermissionOnGesture') || !str_contains($js, 'requestNotifyPermission')) {
    fail('notification permission must be requested from a user gesture for mobile browsers');
}
if (!str_contains($setUi, 'notification shade') && !str_contains($setUi, 'system notification')) {
    fail('settings help should mention phone system notifications');
}
pass('IM and admin-event alerts use the service worker so phones show system notifications');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
