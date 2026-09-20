<?php
declare(strict_types=1);

/**
 * Isolated checks for header cart, Products-page removal, image widths,
 * chat/admin alerts, tracking on wholesale order views, and settings.
 *
 *   php bin/check-header-alerts.php
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

$tmp = sys_get_temp_dir() . '/hd-header-alerts-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Alerts;
use App\Auth;
use App\Catalog;
use App\Database;
use App\Settings;
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

/* ----- image width rewriting ----- */
$raw = 'https://cdn.shopify.com/s/files/1/0744/4017/9989/files/IMG_9843.jpg';
$w400 = Catalog::sizedUrl($raw, 400);
$w800 = Catalog::sizedUrl($raw, 800);
if (!str_contains($w400, 'width=400') || str_contains($w400, 'width=800')) {
    fail('listing sized URL should set width=400, got ' . $w400);
}
if (!str_contains($w800, 'width=800')) {
    fail('detail sized URL should set width=800, got ' . $w800);
}
$replaced = Catalog::sizedUrl($raw . '?width=2000&v=1', 400);
if (!str_contains($replaced, 'width=400') || str_contains($replaced, 'width=2000')) {
    fail('existing width param should be replaced, got ' . $replaced);
}
if (Catalog::sizedUrl($raw, 0) !== $raw) {
    fail('width 0 should leave the URL unchanged');
}
$pdo->prepare("INSERT INTO products (sku, title, image_url, images_json, price_cents, stock, is_public, status)
               VALUES ('IMG-1','Sized Yarn',?, ?, 2400, 4, 1, 'active')")
    ->execute([$raw, json_encode([['src' => $raw . '?width=1200', 'alt' => 'alt']], JSON_UNESCAPED_SLASHES)]);
$pid = (int)$pdo->lastInsertId();
$product = $pdo->query('SELECT * FROM products WHERE id = ' . $pid)->fetch();
$listing = catalog_thumb($product);
if (!str_contains($listing, 'width=400')) {
    fail('home/listing thumbs should request width=400');
}
$gallery = Catalog::images($product, 800);
if (!$gallery || !str_contains($gallery[0]['src'], 'width=800')) {
    fail('product gallery should request width=800');
}
pass('CDN image URLs use width=400 on listings and width=800 on detail galleries');

/* ----- Products page removed; header cart present ----- */
$headerSrc = file_get_contents(APP_ROOT . '/app/Views/layouts/_header.php');
if (str_contains($headerSrc, "p' => 'products'") || str_contains($headerSrc, "['Products'")) {
    fail('header should not link to a public Products page');
}
if (!str_contains($headerSrc, 'nav-cart') || !str_contains($headerSrc, "Icons::get('cart'")) {
    fail('header should include a shopping cart control left of Login');
}
$pub = file_get_contents(APP_ROOT . '/app/Controllers/PublicController.php');
if (!str_contains($pub, "if (\$p === 'products')") || !str_contains($pub, "redirect('home')")) {
    fail('page?p=products should redirect to home');
}
if (is_file(APP_ROOT . '/app/Views/public/products_page.php')) {
    fail('products_page view should be removed');
}
$homeSrc = file_get_contents(APP_ROOT . '/app/Views/public/home.php');
if (str_contains($homeSrc, 'View cart')) {
    fail('home page should not include a View cart button');
}
$idx = file_get_contents(APP_ROOT . '/public/index.php');
if (!str_contains($idx, 'chat.alerts')) {
    fail('router should expose chat.alerts');
}
pass('Products page is gone, cart lives in the header, home has no View cart button');

$header = View::capture('layouts/_header');
if (preg_match('/>Products</', $header) && str_contains($header, 'nav-item')) {
    // The word Products must not be a centred nav item.
    if (preg_match('/class="nav-item[^"]*"[^>]*>Products</', $header)) {
        fail('rendered header still has a Products nav item');
    }
}
if (!str_contains($header, 'nav-cart') || !str_contains($header, 'aria-label="Shopping cart"')) {
    fail('rendered header missing shopping cart icon');
}
$loginPos = strpos($header, 'nav-login');
$cartPos = strpos($header, 'nav-cart');
if ($cartPos === false || $loginPos === false || $cartPos > $loginPos) {
    fail('cart icon should sit to the left of the Login control');
}
pass('rendered header puts the cart icon left of Login and omits Products');

/* ----- settings checkboxes ----- */
if (Settings::imBrowserNotifications() || Settings::adminEventAlerts()) {
    fail('notification settings should default off');
}
Settings::set('im_browser_notifications', '1');
Settings::set('admin_event_alerts', '1');
if (!Settings::imBrowserNotifications() || !Settings::adminEventAlerts()) {
    fail('notification settings should turn on');
}
$setUi = View::capture('admin/settings', ['active' => 'settings', 'settings' => Settings::all(), 'saved' => false]);
if (!str_contains($setUi, 'Browser notifications for Instant Messages') || !str_contains($setUi, 'im_browser_notifications')) {
    fail('settings missing Instant Message browser notification checkbox');
}
if (!str_contains($setUi, 'Admin alerts for client events') || !str_contains($setUi, 'admin_event_alerts')) {
    fail('settings missing admin client-event alert checkbox');
}
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($js, 'chat.alerts') || !str_contains($js, 'function beep') || !str_contains($js, 'browserNotify')) {
    fail('client script should poll chat.alerts and fire beep + browser notifications');
}
pass('Admin Settings expose IM browser notifications and admin event alerts');

/* ----- seed users + order for alerts / tracking ----- */
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('admin12345', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('shop12345', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents, tracking_url) VALUES (?, 'shipped', 'paid', 2400, '1Z999AA10123456784')")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid, $pid, 'Sized Yarn', 1, 1560, 1560]);

$order = $pdo->query('SELECT * FROM orders WHERE id = ' . $oid)->fetch();
$items = $pdo->query('SELECT * FROM order_items WHERE order_id = ' . $oid)->fetchAll();
$view = View::capture('wholesale/order_view', [
    'order' => $order, 'items' => $items, 'placed' => false, 'canModify' => false,
]);
if (!str_contains($view, 'Tracking number') || !str_contains($view, '1Z999AA10123456784')) {
    fail('wholesale order view should always show the tracking number');
}
$emptyOrder = $order;
$emptyOrder['tracking_url'] = '';
$emptyView = View::capture('wholesale/order_view', [
    'order' => $emptyOrder, 'items' => $items, 'placed' => false, 'canModify' => false,
]);
if (!str_contains($emptyView, 'Tracking number') || !str_contains($emptyView, 'Not yet available')) {
    fail('order view should still show a Tracking number field when empty');
}
$print = View::capture('wholesale/order_print', [
    'order' => $order, 'items' => $items, 'cust' => ['first_name' => 'Wendy', 'last_name' => 'Wholesale', 'email' => 'shop@example.com'],
    'company' => 'HouseDye', 'contactEmail' => 'hello@housedye.example', 'contactPhone' => '555',
]);
if (!str_contains($print, 'Tracking number') || !str_contains($print, '1Z999AA10123456784')) {
    fail('order print should include the tracking number');
}
pass('wholesale order view and print always include the tracking number');

/* ----- chat alerts for wholesale clients do not mark read ----- */
$pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, read_by_admin, read_by_client, to_user_id, sender_user_id)
               VALUES (?, 'admin', 'Please check the indigo restock next week', 1, 0, ?, ?)")
    ->execute([$clientId, $clientId, $adminId]);
$mid = (int)$pdo->lastInsertId();

$_SESSION['user'] = ['id' => $clientId, 'role' => 'wholesale', 'email' => 'shop@example.com', 'display_name' => 'Wendy Wholesale'];
$snap = Alerts::snapshot(0, 0);
if (($snap['unread'] ?? 0) < 1) {
    fail('wholesale client should see unread admin messages');
}
$found = null;
foreach ($snap['messages'] as $m) {
    if ((int)$m['id'] === $mid) {
        $found = $m;
    }
}
if (!$found) {
    fail('alerts snapshot should include the admin message');
}
if (($found['preview'] ?? '') !== 'Please check the indigo restock') {
    fail('preview should be the first five words, got ' . ($found['preview'] ?? ''));
}
if (($found['sender_name'] ?? '') !== 'Ada Admin') {
    fail('sender_name should be the admin display name, got ' . ($found['sender_name'] ?? ''));
}
$stillUnread = (int)$pdo->query('SELECT read_by_client FROM messages WHERE id = ' . $mid)->fetchColumn();
if ($stillUnread !== 0) {
    fail('alerts snapshot must not mark messages as read');
}
pass('wholesale users get unread message alerts with sender and five-word preview');

/* ----- admin event alerts ----- */
$_SESSION['user'] = ['id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com', 'display_name' => 'Ada Admin'];
Alerts::log($clientId, 'order', 'New order #99 ($12.00)');
Alerts::log($clientId, 'application', 'New wholesale application from new@example.com');
Alerts::log($clientId, 'order_cancelled', 'Order #7 was cancelled');
Alerts::log($clientId, 'order_completed', 'Order #' . $oid . ' was completed');
$adminSnap = Alerts::snapshot(0, 0);
if (!$adminSnap['admin_event_alerts']) {
    fail('admin snapshot should enable event alerts when the setting is on');
}
$types = array_column($adminSnap['events'], 'type');
foreach (['order', 'application', 'order_cancelled', 'order_completed'] as $need) {
    if (!in_array($need, $types, true)) {
        fail("admin events missing {$need}");
    }
}
$layout = file_get_contents(APP_ROOT . '/app/Views/layouts/app.php');
if (!str_contains($layout, 'chatAlert') || !str_contains($layout, 'Click the chat box to read it')) {
    fail('layout should prompt logged-in users to open the chat box');
}
pass('admins receive order, application, cancelled, and completed event alerts');

/* ----- cart lines carry width=400 images ----- */
$_SESSION['cart'] = ['products' => [$pid => 2], 'bundles' => []];
$_SESSION['user'] = ['id' => $clientId, 'role' => 'wholesale', 'email' => 'shop@example.com', 'display_name' => 'Wendy Wholesale'];
if (cart_count() !== 1) {
    fail('cart_count should report one product line, got ' . cart_count());
}
$ref = new ReflectionClass(\App\Controllers\WholesaleController::class);
$method = $ref->getMethod('buildLines');
$method->setAccessible(true);
$built = $method->invoke(null, $_SESSION['cart']);
$img = $built['lines'][0]['image'] ?? '';
if (!str_contains((string)$img, 'width=400')) {
    fail('cart line images should use width=400, got ' . $img);
}
pass('client cart thumbs request width=400');

/* ----- completing an order via work-order approval logs order_completed ----- */
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 1000)")
    ->execute([$clientId]);
$oid2 = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid2, $pid, 'Sized Yarn', 1, 1000, 1000]);
WorkOrders::onStatusChange($oid2, 'pending', 'provisioning');
foreach (WorkOrders::list() as $row) {
    if ((int)$row['order_id'] === $oid2) {
        WorkOrders::setItemStatus((int)$row['id'], 'complete');
    }
}
if (!WorkOrders::approveOrder($oid2)) {
    fail('approveOrder should succeed');
}
$logged = (int)$pdo->query("SELECT COUNT(*) FROM activity WHERE type='order_completed' AND description LIKE '%#{$oid2}%'")->fetchColumn();
if ($logged < 1) {
    fail('completing an order should write an order_completed activity row');
}
pass('order completion writes an admin-event activity row');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
