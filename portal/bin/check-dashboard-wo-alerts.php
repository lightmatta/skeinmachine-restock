<?php
declare(strict_types=1);

/**
 * Admin dashboard alerts for unassigned provisioning orders and stalled work.
 *
 *   php bin/check-dashboard-wo-alerts.php
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

$tmp = sys_get_temp_dir() . '/hd-dash-wo-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Database;
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('x', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['staff@example.com', password_hash('x', PASSWORD_BCRYPT), 'staff', 'active', 'Sam', 'Staff']);
$staffId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (title, price_cents, stock, is_public, status) VALUES (?,?,?,?,?)")
    ->execute(['Coral Reef', 2400, 10, 1, 'active']);
$pid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'paid', 2400)")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO work_orders (order_id, product_id, title, qty, status) VALUES (?,?,?,?, 'pending')")
    ->execute([$oid, $pid, 'Coral Reef', 6]);
$woId = (int)$pdo->lastInsertId();

$unassigned = WorkOrders::unassignedProvisioning();
if (count($unassigned) !== 1 || (int)$unassigned[0]['order_id'] !== $oid || (int)$unassigned[0]['unassigned_items'] !== 1) {
    fail('provisioning order with no staff should alert, got ' . json_encode($unassigned));
}
if (WorkOrders::stalledItems()) {
    fail('no stalled items yet');
}
pass('unassigned provisioning orders are listed for admins');

$dash = View::capture('admin/dashboard', [
    'active' => 'dashboard',
    'pendingApps' => [],
    'newOrders' => [],
    'unread' => [],
    'activity' => [],
    'stats' => ['orders' => 1, 'pending_pay' => 0, 'clients' => 1, 'revenue' => 0],
    'readyOrders' => [],
    'unassignedOrders' => $unassigned,
    'stalledWork' => [],
]);
if (!str_contains($dash, 'Provisioning orders without staff') || !str_contains($dash, 'Order #' . $oid)) {
    fail('dashboard should alert on unassigned provisioning orders');
}
if (str_contains($dash, 'Stalled work orders')) {
    fail('stalled alert should stay hidden when there are none');
}
pass('dashboard Overview alerts on unassigned provisioning orders');

WorkOrders::assignStaff($woId, $staffId);
if (WorkOrders::unassignedProvisioning()) {
    fail('assigning staff should clear the unassigned alert');
}

WorkOrders::setItemStatus($woId, 'stalled', 'Dye pot overheated');
$stalled = WorkOrders::stalledItems();
if (count($stalled) !== 1 || $stalled[0]['title'] !== 'Coral Reef' || $stalled[0]['notes'] !== 'Dye pot overheated') {
    fail('stalled items should list title and note, got ' . json_encode($stalled));
}
if ((int)$stalled[0]['order_id'] !== $oid) {
    fail('stalled alert should include the order id');
}

$dash2 = View::capture('admin/dashboard', [
    'active' => 'dashboard',
    'pendingApps' => [],
    'newOrders' => [],
    'unread' => [],
    'activity' => [],
    'stats' => ['orders' => 1, 'pending_pay' => 0, 'clients' => 1, 'revenue' => 0],
    'readyOrders' => [],
    'unassignedOrders' => [],
    'stalledWork' => $stalled,
]);
if (!str_contains($dash2, 'Stalled work orders') || !str_contains($dash2, 'Dye pot overheated')) {
    fail('dashboard should alert on stalled work orders with the note');
}
if (str_contains($dash2, 'Provisioning orders without staff')) {
    fail('unassigned alert should hide once staff is allocated');
}
pass('dashboard Overview alerts on stalled work orders');

$pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([$oid]);
if (WorkOrders::unassignedProvisioning() || WorkOrders::stalledItems()) {
    fail('completed orders should leave both dashboard alerts');
}
pass('alerts only cover live provisioning work');

$ctrl = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($ctrl, 'unassignedProvisioning') || !str_contains($ctrl, 'stalledItems')) {
    fail('admin dashboard should load both alert queries');
}
pass('admin dashboard controller feeds both alerts');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
