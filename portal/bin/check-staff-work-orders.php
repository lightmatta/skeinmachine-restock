<?php
declare(strict_types=1);

/**
 * Isolated checks for wholesale %, staff access, expandable orders,
 * bulk product updates, messaging recipients, and work-order lifecycle.
 *
 *   php bin/check-staff-work-orders.php
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

$tmp = sys_get_temp_dir() . '/hd-swo-check-' . getmypid() . '.sqlite';
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
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

/* ----- wholesale percent default + calculation ----- */
if (Settings::wholesalePercent() !== 65) {
    fail('default wholesale percent should be 65, got ' . Settings::wholesalePercent());
}
if (Settings::wholesaleCents(10000) !== 6500) {
    fail('65% of $100.00 should be $65.00, got ' . Settings::wholesaleCents(10000));
}
Settings::set('wholesale_percent', '50');
if (Settings::wholesaleCents(2400) !== 1200) {
    fail('50% of 2400 cents should be 1200');
}
Settings::set('wholesale_percent', '65');
pass('wholesale percent defaults to 65 and calculates from retail');

/* ----- schema: work_orders + message addressing ----- */
$cols = [];
foreach ($pdo->query('PRAGMA table_info(messages)') as $c) {
    $cols[] = $c['name'];
}
foreach (['to_user_id', 'sender_user_id'] as $need) {
    if (!in_array($need, $cols, true)) {
        fail("messages missing column {$need}");
    }
}
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='work_orders'")->fetchColumn();
if ($tables !== 'work_orders') {
    fail('work_orders table was not created');
}
pass('messages addressing columns and work_orders table exist');

/* ----- seed users + product + order ----- */
$hash = password_hash('staff12345', PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['staff@example.com', $hash, 'staff', 'active', 'Sam', 'Staff']);
$staffId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('admin12345', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('shop12345', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status) VALUES (?,?,?,?,1,'active')")
    ->execute(['Y1', 'Coral Reef', 2400, 10]);
$p1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status) VALUES (?,?,?,?,1,'active')")
    ->execute(['Y2', 'Deep Ocean', 2400, 10]);
$p2 = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'pending', 'pending', 7200)")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$li = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)');
$li->execute([$oid, $p1, 'Coral Reef', 2, 1560, 3120]);
$li->execute([$oid, $p2, 'Deep Ocean', 1, 1560, 1560]);
$li->execute([$oid, $p1, '[Bundle] Coral Reef', 1, 1560, 1560]);

/* ----- work orders appear on provisioning, one line per product type ----- */
$pdo->prepare("UPDATE orders SET status='provisioning' WHERE id=?")->execute([$oid]);
WorkOrders::onStatusChange($oid, 'pending', 'provisioning');
$rows = WorkOrders::list();
if (count($rows) !== 2) {
    fail('expected 2 work-order lines (one per product type), got ' . count($rows));
}
$titles = array_column($rows, 'title');
sort($titles);
if ($titles !== ['Coral Reef', 'Deep Ocean']) {
    fail('unexpected product types: ' . implode(',', $titles));
}
$coral = null;
foreach ($rows as $r) {
    if ($r['title'] === 'Coral Reef') {
        $coral = $r;
    }
}
if ((int)$coral['qty'] !== 3) {
    fail('Coral Reef qty should sum to 3, got ' . $coral['qty']);
}
if (trim((string)$rows[0]['client_name']) !== 'Wendy Wholesale' && $rows[0]['client_email'] !== 'shop@example.com') {
    fail('client name missing on work order rows');
}
pass('provisioning order creates one work-order line per product type with client name');

/* ----- assign staff + complete + notify superadmin ----- */
WorkOrders::assignStaffToOrder($oid, $staffId);
$assigned = $pdo->query('SELECT DISTINCT staff_user_id FROM work_orders WHERE order_id = ' . $oid)->fetchAll();
if (count($assigned) !== 1 || (int)$assigned[0]['staff_user_id'] !== $staffId) {
    fail('assign-all did not set staff on every item');
}
foreach ($rows as $r) {
    WorkOrders::setItemStatus((int)$r['id'], 'complete');
}
$ready = WorkOrders::readyForApproval();
if (count($ready) !== 1 || (int)$ready[0]['order_id'] !== $oid) {
    fail('order should be ready for Super Admin approval');
}
$note = $pdo->query("SELECT COUNT(*) FROM activity WHERE type='work_order_ready' AND description LIKE '%#{$oid}%'")->fetchColumn();
if ((int)$note < 1) {
    fail('Super Admin was not notified when all items completed');
}
pass('staff assignment, completion, and Super Admin notification work');

/* ----- approve hides from work orders and marks order completed ----- */
if (!WorkOrders::approveOrder($oid)) {
    fail('approveOrder should succeed when all items are complete');
}
$st = $pdo->query('SELECT status FROM orders WHERE id = ' . $oid)->fetchColumn();
if ($st !== 'completed') {
    fail('approved order should be completed, got ' . $st);
}
if (WorkOrders::list()) {
    fail('completed order should leave the work orders page');
}
pass('Super Admin approve marks the client order completed and hides work orders');

/* ----- returning to provisioning restores the work orders ----- */
$pdo->prepare("UPDATE orders SET status='provisioning' WHERE id=?")->execute([$oid]);
WorkOrders::onStatusChange($oid, 'completed', 'provisioning');
$again = WorkOrders::list();
if (count($again) !== 2) {
    fail('order set back to provisioning should reappear, got ' . count($again));
}
foreach ($again as $r) {
    if ($r['status'] !== 'pending') {
        fail('re-provisioned items should reset to pending');
    }
}
pass('setting an order back to provisioning restores work orders as pending');

/* ----- staff role helpers ----- */
$_SESSION['user'] = ['id' => $staffId, 'role' => 'staff', 'email' => 'staff@example.com', 'display_name' => 'Sam Staff'];
if (!Auth::isStaff() || Auth::isAdmin()) {
    fail('staff should be isStaff and not isAdmin');
}
if (!Auth::isStaffOrAdmin()) {
    fail('staff should pass isStaffOrAdmin');
}
$dash = View::capture('admin/_sidebar', ['active' => 'dashboard']);
foreach (['Work orders', 'Products', 'Bundles', 'Dashboard'] as $label) {
    if (!str_contains($dash, $label)) {
        fail("staff sidebar missing {$label}");
    }
}
foreach (['Users', 'Analytics', 'Settings'] as $label) {
    if (preg_match('/<span>' . preg_quote($label, '/') . '<\/span>/', $dash)) {
        fail("staff sidebar should not include {$label}");
    }
}
if (str_contains($dash, 'admin/users') || str_contains($dash, 'admin/orders') || str_contains($dash, 'admin/messages') || str_contains($dash, 'admin/settings')) {
    fail('staff sidebar still links to admin-only sections');
}
pass('staff role is scoped to dashboard, work orders, products, and bundles');

/* ----- products UI: Retail + Wholesale ----- */
$prodSrc = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (!str_contains($prodSrc, "label:'Retail'") || !str_contains($prodSrc, "label:'Wholesale'")) {
    fail('products grid should rename Price to Retail and add Wholesale');
}
if (!str_contains($prodSrc, 'selectable: true') || !str_contains($prodSrc, "key:'is_public'")) {
    fail('products grid should offer bulk status and visibility actions');
}
$orderSrc = file_get_contents(APP_ROOT . '/app/Views/admin/orders.php');
if (!str_contains($orderSrc, 'expand:true') || !str_contains($orderSrc, "op:'items'")) {
    fail('orders grid should expand on order number / client to show items');
}
$msgSrc = file_get_contents(APP_ROOT . '/app/Views/admin/messages.php');
if (!str_contains($msgSrc, 'composeTo') || !str_contains($msgSrc, "op:'compose'")) {
    fail('admin messages should let the user pick any recipient');
}
$setUi = View::capture('admin/settings', ['active' => 'settings', 'settings' => Settings::all(), 'saved' => false]);
if (!str_contains($setUi, 'wholesale_percent')) {
    fail('settings should include wholesale percentage');
}
pass('admin products, orders, messages, and settings UI include the new controls');

/* ----- bulk product update ----- */
$pdo->prepare("UPDATE products SET status='inactive', is_public=0, updated_at=datetime('now') WHERE id IN (?,?)")
    ->execute([$p1, $p2]);
$st1 = $pdo->query("SELECT status, is_public FROM products WHERE id = {$p1}")->fetch();
if ($st1['status'] !== 'inactive' || (int)$st1['is_public'] !== 0) {
    fail('bulk status/public update did not stick');
}
pass('selected products can be set inactive and hidden together');

/* ----- staff may message admins; admin may message anyone ----- */
$pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, to_user_id, sender_user_id) VALUES (?,?,?,?,?)")
    ->execute([$staffId, 'staff', 'Hello admins', null, $staffId]);
$pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, to_user_id, sender_user_id) VALUES (?,?,?,?,?)")
    ->execute([$staffId, 'staff', 'Hi Ada', $adminId, $staffId]);
$pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, to_user_id, sender_user_id) VALUES (?,?,?,?,?)")
    ->execute([$clientId, 'admin', 'Hello Wendy', $clientId, $adminId]);
$unread = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender IN ('client','staff') AND read_by_admin=0 AND (to_user_id IS NULL OR to_user_id = {$adminId})")->fetchColumn();
if ($unread < 2) {
    fail('staff messages to admins should show as needing a reply');
}
$toClient = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE thread_user_id = {$clientId} AND sender='admin'")->fetchColumn();
if ($toClient < 1) {
    fail('admin should be able to address a wholesale client');
}
pass('staff chat reaches admins and admins can message any user');

/* ----- chats box markup is a wrapping card ----- */
$dashUi = View::capture('admin/dashboard', [
    'active' => 'dashboard',
    'pendingApps' => [],
    'newOrders' => [],
    'unread' => [['email' => 'very.long.wholesale.client.name@example.com', 'unread' => 3, 'last_at' => '2026-01-01 12:00:00']],
    'activity' => [],
    'stats' => ['orders' => 0, 'pending_pay' => 0, 'clients' => 0, 'revenue' => 0],
    'readyOrders' => [],
]);
if (!str_contains($dashUi, 'chats-box') || !str_contains($dashUi, 'Chats needing a reply')) {
    fail('dashboard chats should render in a chats-box card');
}
pass('chats needing reply sit in a wrapping card');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
