<?php
declare(strict_types=1);

/**
 * Work-order complete-row highlighting, collapse defaults, and qty alerts.
 *
 *   php bin/check-wo-complete-collapse.php
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

function groupsFrom(array $rows): array
{
    $groups = [];
    foreach ($rows as $w) {
        $oid = (int)$w['order_id'];
        if (!isset($groups[$oid])) {
            $groups[$oid] = [
                'client_name'  => $w['client_name'],
                'client_email' => $w['client_email'],
                'items'        => [],
                'all_complete' => true,
            ];
        }
        $groups[$oid]['items'][] = $w;
        $lineDone = !empty($w['all_subtasks_done']);
        if (!$lineDone) {
            $groups[$oid]['all_complete'] = false;
        }
    }
    return $groups;
}

$tmp = sys_get_temp_dir() . '/hd-wo-collapse-' . getmypid() . '.sqlite';
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
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

$cols = [];
foreach ($pdo->query('PRAGMA table_info(work_orders)') as $c) {
    $cols[] = $c['name'];
}
foreach (['qty_was', 'qty_changed_at'] as $need) {
    if (!in_array($need, $cols, true)) {
        fail("work_orders missing {$need}");
    }
}
pass('qty_was and qty_changed_at columns exist');

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('admin12345', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('shop12345', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt, warehouse_stock) VALUES (?,?,?,?,1,'active',?,?)")
    ->execute(['Y1', 'Coral Reef', 2400, 10, 10, 0]);
$p1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt, warehouse_stock) VALUES (?,?,?,?,1,'active',?,?)")
    ->execute(['Y2', 'Deep Ocean', 2400, 10, 10, 2]);
$p2 = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'pending', 'pending', 0)")
    ->execute([$clientId]);
$oid = (int)$pdo->lastInsertId();
$li = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)');
$li->execute([$oid, $p1, 'Coral Reef', 13, 1560, 20280]);
$li->execute([$oid, $p2, 'Deep Ocean', 8, 1560, 12480]);

$pdo->prepare("UPDATE orders SET status='provisioning' WHERE id=?")->execute([$oid]);
WorkOrders::onStatusChange($oid, 'pending', 'provisioning');

$grouped = WorkOrders::listGrouped();
$coral = $ocean = null;
foreach ($grouped as $w) {
    if ($w['title'] === 'Coral Reef') {
        $coral = $w;
    }
    if ($w['title'] === 'Deep Ocean') {
        $ocean = $w;
    }
}
if (!$coral || count($coral['trays']) !== 2) {
    fail('Coral Reef should start with 2 trays');
}
if ($coral['all_subtasks_done'] || $coral['default_collapsed']) {
    fail('incomplete trays should keep the parent expanded');
}
if ($coral['qty_shortfall'] || $coral['qty_changed']) {
    fail('fresh work orders should not flag a qty change or shortfall');
}
if (!$ocean || $ocean['trays'] || $ocean['all_subtasks_done']) {
    fail('Deep Ocean without trays should stay pending and have no badge');
}
pass('incomplete work orders default to expanded and have no qty alert');

$_SESSION['user'] = [
    'id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com',
    'display_name' => 'Ada Admin', 'first_name' => 'Ada', 'last_name' => 'Admin',
];

$html = View::capture('admin/work_orders', [
    'title' => 'Work orders', 'active' => 'work-orders',
    'rows' => $grouped, 'groups' => groupsFrom($grouped), 'staff' => [],
    'isSuper' => true, 'isAdmin' => true,
]);
if (!str_contains($html, 'wo-toggle') || !str_contains($html, 'wo-tray-count')) {
    fail('parent product name should be a toggle with a subtask count badge');
}
if (!preg_match('/data-wo="' . (int)$coral['id'] . '"[^>]*class="[^"]*is-open/', $html)
    && !preg_match('/class="[^"]*is-open[^"]*"[^>]*data-wo="' . (int)$coral['id'] . '"/', $html)) {
    if (!str_contains($html, 'data-wo="' . (int)$coral['id'] . '"') || !str_contains($html, 'is-open')) {
        fail('incomplete Coral Reef block should default to is-open');
    }
}
if (str_contains($html, 'wo-qty-alert')) {
    fail('no qty alert on a freshly provisioned order');
}
if (substr_count($html, '>' . count($coral['trays']) . '<') < 1 && !str_contains($html, '>' . count($coral['trays']) . '</span>')) {
    fail('subtask count badge should show the tray count');
}
if (!str_contains($html, '<span class="wo-tray-count"') || !str_contains($html, '>' . count($coral['trays']) . '</span>')) {
    fail('circle badge should contain the number of sub-tasks');
}
pass('markup includes product-name toggle, count badge, and expanded default');

foreach ($coral['trays'] as $i => $tray) {
    $status = $i === 0 ? 'complete' : 'filled_from_stock';
    WorkOrders::setItemStatus((int)$tray['id'], $status);
}
$grouped = WorkOrders::listGrouped();
foreach ($grouped as $w) {
    if ($w['title'] === 'Coral Reef') {
        $coral = $w;
    }
}
if (!$coral['all_subtasks_done'] || !$coral['default_collapsed']) {
    fail('all-done trays should mark the parent complete and collapse it');
}

$htmlDone = View::capture('admin/work_orders', [
    'title' => 'Work orders', 'active' => 'work-orders',
    'rows' => $grouped, 'groups' => groupsFrom($grouped), 'staff' => [],
    'isSuper' => true, 'isAdmin' => true,
]);
if (!str_contains($htmlDone, 'wo-parent wo-done')) {
    fail('parent row should be pale-green when every subtask is done');
}
if (!str_contains($htmlDone, 'wo-tray wo-done')) {
    fail('complete and filled-from-stock tray rows should be pale-green');
}
if (!preg_match('/class="wo-block is-collapsed"/', $htmlDone)) {
    fail('all-done work order should default to collapsed');
}
pass('done trays and their parent render green and collapse by default');

$pdo->prepare('UPDATE order_items SET qty = ?, line_total_cents = ? WHERE order_id = ? AND product_id = ?')
    ->execute([25, 25 * 1560, $oid, $p1]);
WorkOrders::syncFromOrder($oid, false);

$changed = $pdo->query('SELECT qty, qty_was, qty_changed_at FROM work_orders WHERE id = ' . (int)$coral['id'])->fetch();
if ((int)$changed['qty'] !== 25 || (int)$changed['qty_was'] !== 13 || trim((string)$changed['qty_changed_at']) === '') {
    fail('admin qty bump should record qty_was and qty_changed_at, got ' . json_encode($changed));
}

$grouped = WorkOrders::listGrouped();
foreach ($grouped as $w) {
    if ($w['title'] === 'Coral Reef') {
        $coral = $w;
    }
}
if (!$coral['qty_changed'] || !$coral['qty_shortfall']) {
    fail('qty bump that leaves trays short should flag changed + shortfall');
}
if ((int)$coral['covered_qty'] >= (int)$coral['ordered_qty']) {
    fail('covered qty should stay below the new ordered amount after a leftover-tray bump');
}
if ($coral['default_collapsed'] || $coral['all_subtasks_done']) {
    fail('new pending tray after a qty bump should expand the work order');
}

$htmlAlert = View::capture('admin/work_orders', [
    'title' => 'Work orders', 'active' => 'work-orders',
    'rows' => $grouped, 'groups' => groupsFrom($grouped), 'staff' => [],
    'isSuper' => true, 'isAdmin' => true,
]);
foreach (['wo-qty-alert', 'Check originating order quantities', 'qty updated from 13 to 25', 'trays cover', 'Open originating order'] as $needle) {
    if (!str_contains($htmlAlert, $needle)) {
        fail('qty alert should mention ' . $needle);
    }
}
if (!str_contains($htmlAlert, 'is-open')) {
    fail('shortfall work order should default to expanded subtasks');
}
pass('admin order qty increase records impact and alerts when trays are short');

$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($css, 'tr.wo-done td') || !str_contains($css, '.wo-block.is-collapsed .wo-tray') || !str_contains($css, '.wo-tray-count')) {
    fail('CSS should green done rows, hide collapsed trays, and style the count circle');
}
$woSrc = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders.php');
if (!str_contains($woSrc, 'wo-toggle') || !str_contains($woSrc, 'aria-expanded')) {
    fail('product name should toggle subtask visibility');
}
pass('CSS and toggle wiring are present');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
