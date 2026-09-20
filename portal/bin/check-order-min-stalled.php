<?php
declare(strict_types=1);

/**
 * Isolated checks for product min qty, editable user emails, order line
 * editing, and stalled work-order admin notifications.
 *
 *   php bin/check-order-min-stalled.php
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

$tmp = sys_get_temp_dir() . '/hd-order-min-' . getmypid() . '.sqlite';
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
use App\Database;
use App\Settings;
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

/* ----- global default + products Min column ----- */
if (Settings::productMinQty() !== 10) {
    fail('product min qty should default to 10, got ' . Settings::productMinQty());
}
$setUi = View::capture('admin/settings', ['active' => 'settings', 'settings' => Settings::all(), 'saved' => false]);
if (!str_contains($setUi, 'name="product_min_qty"') || !str_contains($setUi, 'Default product minimum')) {
    fail('Settings should expose the default product minimum');
}
$prodSrc = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (!str_contains($prodSrc, "key:'min_qty'") || !str_contains($prodSrc, "label:'Min'")) {
    fail('Products grid should include an admin-editable Min column');
}
$cols = [];
foreach ($pdo->query('PRAGMA table_info(products)') as $c) {
    $cols[] = $c['name'];
}
if (!in_array('min_qty', $cols, true)) {
    fail('products.min_qty column missing');
}
$pdo->exec("INSERT INTO products (title, price_cents, stock, is_public) VALUES ('Min Yarn', 2000, 8, 1)");
$pid = (int)$pdo->lastInsertId();
$min = (int)$pdo->query('SELECT min_qty FROM products WHERE id = ' . $pid)->fetchColumn();
if ($min !== 10) {
    fail('new products should inherit min_qty 10, got ' . $min);
}
Settings::set('product_min_qty', '12');
if (Settings::productMinQty() !== 12) {
    fail('global product min should update');
}
$pdo->prepare('INSERT INTO products (title, price_cents, stock, is_public, min_qty) VALUES (?,?,?,?,?)')
    ->execute(['Custom Min', 1000, 3, 1, 12]);
$pdo->prepare('UPDATE products SET min_qty = 4 WHERE id = ?')->execute([$pid]);
if (Settings::minQtyForProduct($pdo->query('SELECT * FROM products WHERE id = ' . $pid)->fetch()) !== 4) {
    fail('per-product min should override the global default');
}
$adminSrc = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, "'min_qty'") || !str_contains($adminSrc, 'product_min_qty')) {
    fail('admin API/settings should persist min_qty');
}
pass('Products Min defaults to 10 from Settings and is stored per product');

/* ----- editable user email ----- */
$userSrc = file_get_contents(APP_ROOT . '/app/Views/admin/users.php');
if (!str_contains($userSrc, "key:'email', label:'Email', editable:true")) {
    fail('Users grid email should be editable');
}
if (!str_contains($adminSrc, "'email'") || !str_contains($adminSrc, 'duplicate_email')) {
    fail('admin user update should validate unique emails');
}
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['ada@example.com', password_hash('admin12345', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('shop12345', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['staff@example.com', password_hash('staff12345', PASSWORD_BCRYPT), 'staff', 'active', 'Sam', 'Staff']);
$staffId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute(['wendy@shop.example', $clientId]);
$got = $pdo->query('SELECT email FROM users WHERE id = ' . $clientId)->fetchColumn();
if ($got !== 'wendy@shop.example') {
    fail('user email should be updatable, got ' . $got);
}
pass('Admins can edit a user email address');

/* ----- add / edit / delete order line items ----- */
$ordSrc = file_get_contents(APP_ROOT . '/app/Views/admin/orders.php');
foreach (['add_item', 'update_item', 'delete_item', 'oi-add-btn'] as $needle) {
    if (!str_contains($ordSrc, $needle)) {
        fail('orders expand panel should let admins add/edit/delete items, missing ' . $needle);
    }
}
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'pending', 'pending', 0)")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid, $pid, 'Min Yarn', 10, 1300, 13000]);
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid, $pid, 'Min Yarn extra', 2, 1300, 2600]);
$pdo->exec("UPDATE orders SET total_cents = 15600 WHERE id = {$oid}");

$pdo->prepare('UPDATE order_items SET qty = 5, line_total_cents = 6500 WHERE order_id = ? AND title = ?')
    ->execute([$oid, 'Min Yarn extra']);
$pdo->prepare('DELETE FROM order_items WHERE order_id = ? AND title = ?')->execute([$oid, 'Min Yarn extra']);
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid, $pid, 'Added by admin', 3, 1300, 3900]);
$sum = (int)$pdo->query('SELECT COALESCE(SUM(line_total_cents),0) FROM order_items WHERE order_id = ' . $oid)->fetchColumn();
$pdo->prepare('UPDATE orders SET total_cents = ? WHERE id = ?')->execute([$sum, $oid]);
$titles = $pdo->query('SELECT title FROM order_items WHERE order_id = ' . $oid . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($titles !== ['Min Yarn', 'Added by admin'] || $sum !== 16900) {
    fail('order lines should add/edit/delete, got ' . json_encode([$titles, $sum]));
}
if (!str_contains($adminSrc, 'mutateOrderItem') || !str_contains($adminSrc, 'refreshOrderTotals')) {
    fail('admin API should mutate order items and refresh the order total');
}
$pdo->prepare("UPDATE orders SET status = 'provisioning' WHERE id = ?")->execute([$oid]);
WorkOrders::onStatusChange($oid, 'pending', 'provisioning');
$wo = WorkOrders::list();
if (count($wo) !== 1 || (int)$wo[0]['qty'] !== 13) {
    fail('provisioning should roll edited lines into one work-order qty, got ' . json_encode($wo));
}
$keepId = (int)$wo[0]['id'];
$pdo->prepare('DELETE FROM order_items WHERE title = ?')->execute(['Added by admin']);
WorkOrders::syncFromOrder($oid, false);
$wo2 = WorkOrders::list();
if (count($wo2) !== 1 || (int)$wo2[0]['qty'] !== 10) {
    fail('removing a line should update work-order qty, got ' . json_encode($wo2));
}
pass('Admins can add, edit, and delete items on an existing order');

/* ----- stalled work orders notify admins ----- */
$woSrc = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders.php');
if (!str_contains($woSrc, 'stalled') || !str_contains($woSrc, 'wo-note')) {
    fail('work orders UI should offer stalled status with a note');
}
$_SESSION['user'] = ['id' => $staffId, 'role' => 'staff', 'email' => 'staff@example.com', 'display_name' => 'Sam Staff'];
WorkOrders::setItemStatus($keepId, 'stalled', '');
$still = $pdo->query('SELECT status FROM work_orders WHERE id = ' . $keepId)->fetchColumn();
if ($still !== 'pending') {
    fail('stalled without a note must not stick, got ' . $still);
}
WorkOrders::setItemStatus($keepId, 'stalled', 'Dye lot failed QC');
$row = $pdo->query('SELECT status, notes FROM work_orders WHERE id = ' . $keepId)->fetch();
if ($row['status'] !== 'stalled' || $row['notes'] !== 'Dye lot failed QC') {
    fail('stalled status and note should save, got ' . json_encode($row));
}
$act = $pdo->query("SELECT type, description FROM activity WHERE type='work_order_stalled' ORDER BY id DESC LIMIT 1")->fetch();
if (!$act || !str_contains((string)$act['description'], 'Dye lot failed QC') || !str_contains((string)$act['description'], 'Min Yarn')) {
    fail('stalling should write an admin activity row, got ' . json_encode($act));
}
if (!in_array('work_order_stalled', Alerts::EVENT_TYPES, true)) {
    fail('stalled work orders should be an admin-event notification type');
}
Settings::set('admin_event_alerts', '1');
$_SESSION['user'] = ['id' => $adminId, 'role' => 'admin', 'email' => 'ada@example.com', 'display_name' => 'Ada Admin'];
$snap = Alerts::snapshot(0, 0);
$found = false;
foreach ($snap['events'] as $e) {
    if ($e['type'] === 'work_order_stalled' && $e['title'] === 'Work order stalled') {
        $found = true;
    }
}
if (!$found) {
    fail('admins should receive a system notification for stalled work, got ' . json_encode($snap['events']));
}
$ready = WorkOrders::readyForApproval();
if ($ready) {
    fail('a stalled item must block Super Admin approval');
}
pass('Staff can stall a work-order item with a note and admins are notified');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
