<?php
declare(strict_types=1);

/**
 * Tray-rate scheduling, user discount override, manual order discount,
 * and homepage colour filters.
 *
 *   php bin/check-schedule-tray-discount.php
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

$tmp = sys_get_temp_dir() . '/hd-tray-discount-' . getmypid() . '.sqlite';
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
use App\Settings;
use App\ShopifyCsv;
use App\WorkOrders;

$pdo = Database::pdo();

$cols = [];
foreach ($pdo->query('PRAGMA table_info(users)') as $c) {
    $cols[] = $c['name'];
}
foreach (['tray_rate', 'discount_percent'] as $need) {
    if (!in_array($need, $cols, true)) {
        fail("users missing {$need}");
    }
}
$ocols = [];
foreach ($pdo->query('PRAGMA table_info(orders)') as $c) {
    $ocols[] = $c['name'];
}
if (!in_array('manual_discount_cents', $ocols, true)) {
    fail('orders.manual_discount_cents missing');
}
$pcols = [];
foreach ($pdo->query('PRAGMA table_info(products)') as $c) {
    $pcols[] = $c['name'];
}
if (!in_array('colours', $pcols, true)) {
    fail('products.colours missing');
}
pass('schema has tray_rate, discount_percent, manual_discount_cents, colours');

$usersSrc = file_get_contents(APP_ROOT . '/app/Views/admin/users.php');
if (!str_contains($usersSrc, "tip:'Ignore minimum order quantities'")) {
    fail('Ignore min hover should read Ignore minimum order quantities');
}
if (!str_contains($usersSrc, "label:'Discount'") || !str_contains($usersSrc, "tip:'Override Discount Rate'")) {
    fail('Users grid should include Discount / Override Discount Rate');
}
if (!str_contains($usersSrc, "label:'Tray rate'")) {
    fail('Users grid should include Tray rate');
}
$ordSrc = file_get_contents(APP_ROOT . '/app/Views/admin/orders.php');
if (!str_contains($ordSrc, "label:'Manual Discount'") || !str_contains($ordSrc, 'manual_discount_cents')) {
    fail('Orders grid should include Manual Discount');
}
if (!str_contains($ordSrc, "label:'Discount'") || !str_contains($ordSrc, "tip:'Override Discount Rate'")) {
    fail('Orders grid should show the customer Override Discount Rate');
}
$print = file_get_contents(APP_ROOT . '/app/Views/wholesale/order_print.php');
if (!str_contains($print, 'Additional Discount')) {
    fail('order print should list Additional Discount');
}
$home = file_get_contents(APP_ROOT . '/app/Views/public/home.php');
if (!str_contains($home, 'colour-filter') || !str_contains($home, 'data-variegated') || !str_contains($home, 'colourSwatches')) {
    fail('homepage should offer colour chips and a variegated toggle');
}
$gantt = file_get_contents(APP_ROOT . '/public/assets/gantt.js');
if (!str_contains($gantt, 'printA4') || !str_contains($gantt, 'overrideRate') || !str_contains($gantt, 'is-conflict')) {
    fail('gantt should print A4, override tray-rate warnings, and highlight conflicts');
}
$sched = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders_schedule.php');
if (!str_contains($sched, 'Print A4') || !str_contains($sched, 'ganttOverrideRate')) {
    fail('schedule toolbar should include Print A4 and the override checkbox');
}
if (str_contains($gantt, 'window.alert')) {
    fail('gantt should not alert on tray-rate conflicts');
}
pass('admin UI copy, print discount, colour filter, and gantt controls are wired');

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, tray_rate, discount_percent) VALUES (?,?,?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('x', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin', 10, 0]);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, discount_percent) VALUES (?,?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('x', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale', 50]);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, tray_rate) VALUES (?,?,?,?,?,?,?)")
    ->execute(['staff@example.com', password_hash('x', PASSWORD_BCRYPT), 'staff', 'active', 'Sam', 'Staff', 2]);
$staffId = (int)$pdo->lastInsertId();

$cust = $pdo->query('SELECT * FROM users WHERE id = ' . $clientId)->fetch();
if (Settings::wholesalePercentFor($cust) !== 50) {
    fail('user discount_percent should override the global wholesale percent');
}
if (Settings::wholesaleCents(2000, $cust) !== 1000) {
    fail('50% of $20.00 should be $10.00 for the override user');
}
if (Settings::wholesaleCents(2000) !== 1300) {
    fail('global 65% should still apply without a user override');
}
pass('per-user Override Discount Rate changes wholesale pricing');

$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt, colours) VALUES (?,?,?,?,1,'active',?,?)")
    ->execute(['Y1', 'Coral Reef', 2400, 10, 10, 'coral,red,variegated']);
$p1 = (int)$pdo->lastInsertId();
if (!Catalog::productHasColour(['colours' => 'coral,red,variegated'], 'coral') || !Catalog::productHasColour(['colours' => 'coral,red,variegated'], 'variegated')) {
    fail('colour parser should keep coral and variegated');
}
if (Catalog::coloursCsv('Ocean, Forest, Variegated') !== 'blue,green,variegated') {
    fail('colour aliases should map ocean/forest, got ' . Catalog::coloursCsv('Ocean, Forest, Variegated'));
}
$fh = fopen('php://temp', 'r+');
fputcsv($fh, ['Handle', 'Title', 'Variant SKU', 'Variant Price', 'Variant Inventory Qty', 'Published', 'Status', 'Colours']);
fputcsv($fh, ['deep-ocean', 'Deep Ocean', 'Y2', '24.00', '4', 'true', 'active', 'blue, navy']);
rewind($fh);
$csv = stream_get_contents($fh);
fclose($fh);
$imp = ShopifyCsv::import($csv);
if ((int)($imp['created'] ?? 0) < 1) {
    fail('CSV should create Deep Ocean, got ' . json_encode($imp));
}
$p2 = $pdo->query("SELECT colours FROM products WHERE sku = 'Y2'")->fetchColumn();
if (!str_contains((string)$p2, 'blue') || !str_contains((string)$p2, 'navy')) {
    fail('CSV colours metafield should store blue,navy got ' . $p2);
}
pass('colour metafield parsing and CSV import work');

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 0)")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid, $p1, 'Coral Reef', 35, 1560, 54600]);
WorkOrders::syncFromOrder($oid, false);
$grouped = WorkOrders::listGrouped();
if (!$grouped || count($grouped[0]['trays'] ?? []) < 3) {
    fail('35 / SPT 10 should spawn at least 3 trays');
}
$days = [];
foreach ($grouped[0]['trays'] as $t) {
    if ($t['starts_at'] !== $t['ends_at']) {
        fail('default tray boxes should be 1 day, got ' . $t['starts_at'] . '–' . $t['ends_at']);
    }
    $days[$t['starts_at']] = ($days[$t['starts_at']] ?? 0) + 1;
}
if (max($days) > 10) {
    fail('unassigned default pack should not exceed the default 10 tray rate');
}
WorkOrders::assignStaffToOrder($oid, $staffId);
$after = WorkOrders::listGrouped()[0];
$byDay = [];
foreach ($after['trays'] as $t) {
    if ((int)$t['staff_user_id'] !== $staffId) {
        fail('assigned trays should belong to the staff member');
    }
    if ($t['starts_at'] !== $t['ends_at']) {
        fail('assigned trays should stay 1-day boxes');
    }
    $byDay[$t['starts_at']] = ($byDay[$t['starts_at']] ?? 0) + 1;
}
if (max($byDay) > 2) {
    fail('staff tray rate 2 should cap a day at 2 trays, got ' . json_encode($byDay));
}
if (count($byDay) < 2) {
    fail('overflow trays should move to the next day');
}
if (min(array_keys($byDay)) !== date('Y-m-d')) {
    fail('a free staff member should start on today, got ' . json_encode($byDay));
}
pass('default 1-day boxes stagger to the staff tray rate and overflow to the next day');

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 0)")
    ->execute([$clientId]);
$oid2 = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid2, $p1, 'Coral Reef', 25, 1560, 39000]);
WorkOrders::syncFromOrder($oid2, false);
WorkOrders::assignStaffToOrder($oid2, $staffId);
$conflicts = WorkOrders::scheduleConflicts();
// After auto-pack across both orders, days should stay at rate.
foreach ($conflicts as $c) {
    if ((int)$c['staff_user_id'] === $staffId && (int)$c['count'] > (int)$c['rate']) {
        fail('auto-pack left a conflict: ' . json_encode($conflicts));
    }
}
$allTrays = [];
foreach (WorkOrders::listGrouped() as $w) {
    foreach ($w['trays'] as $t) {
        if ((int)$t['staff_user_id'] === $staffId) {
            $allTrays[] = $t;
        }
    }
}
$today = date('Y-m-d');
WorkOrders::setSchedule((int)$allTrays[0]['id'], $today, $today);
WorkOrders::setSchedule((int)$allTrays[1]['id'], $today, $today);
WorkOrders::setSchedule((int)$allTrays[2]['id'], $today, $today);
$over = WorkOrders::scheduleConflicts();
if (!$over) {
    fail('three trays on one day for a rate-2 staff member should conflict');
}
$ids = [];
foreach ($over as $c) {
    foreach ($c['ids'] as $id) {
        $ids[] = $id;
    }
}
if (count($ids) < 3) {
    fail('conflict list should include the overlapping trays');
}
pass('conflicts list every tray that exceeds a staff member\'s daily rate');

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents, manual_discount_cents) VALUES (?, 'pending', 'pending', 5000, 750)")
    ->execute([$clientId]);
$oid3 = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)')
    ->execute([$oid3, $p1, 'Coral Reef', 2, 2500, 5000]);
$order = $pdo->query('SELECT * FROM orders WHERE id = ' . $oid3)->fetch();
$items = $pdo->query('SELECT * FROM order_items WHERE order_id = ' . $oid3)->fetchAll();
$tot = order_totals($order, $items);
if ($tot['subtotal'] !== 5000 || $tot['discount'] !== 750 || $tot['total'] !== 4250) {
    fail('manual discount should subtract from the billable total, got ' . json_encode($tot));
}
pass('Manual Discount subtracts from the billable total as Additional Discount');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "schedule tray-rate / discount / colour checks passed\n";
