<?php
declare(strict_types=1);

/**
 * Work-order trays, SPT/WS product columns, warehouse credit, and schedule UI.
 *
 *   php bin/check-wo-schedule-spt.php
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

$tmp = sys_get_temp_dir() . '/hd-wo-spt-' . getmypid() . '.sqlite';
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

/* ----- tray math ----- */
if (WorkOrders::trayPlan(8, 10) !== []) {
    fail('qty <= SPT should not create trays');
}
if (WorkOrders::trayPlan(20, 10) !== [10, 10]) {
    fail('20 / SPT 10 should be 10+10');
}
if (WorkOrders::trayPlan(13, 10) !== [10, 3]) {
    fail('13 / SPT 10 should be 10+3');
}
if (WorkOrders::trayPlan(23, 10) !== [10, 10, 3]) {
    fail('23 / SPT 10 should be 10+10+3');
}
pass('trayPlan splits leftover skeins onto a final short tray');

/* ----- settings default SPT ----- */
if (Settings::productSpt() !== 10) {
    fail('default product_spt should be 10, got ' . Settings::productSpt());
}
$setUi = file_get_contents(APP_ROOT . '/app/Views/admin/settings.php');
if (!str_contains($setUi, 'name="product_spt"') || !str_contains($setUi, 'Default skeins per tray')) {
    fail('settings should expose default SPT');
}
$adminSrc = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, 'product_spt') || !str_contains($adminSrc, "'spt'") || !str_contains($adminSrc, "'warehouse_stock'")) {
    fail('admin API should persist SPT and warehouse stock');
}
pass('SPT default lives in settings and the admin product whitelist');

/* ----- product columns ----- */
$prodCols = [];
foreach ($pdo->query('PRAGMA table_info(products)') as $c) {
    $prodCols[] = $c['name'];
}
foreach (['spt', 'warehouse_stock'] as $need) {
    if (!in_array($need, $prodCols, true)) {
        fail("products missing {$need}");
    }
}
$prodSrc = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (!str_contains($prodSrc, "label:'SPT'") || !str_contains($prodSrc, "tip:'Skeins Per Tray'")) {
    fail('products grid should show SPT with a Skeins Per Tray hover tip');
}
if (!str_contains($prodSrc, "label:'WS'") || !str_contains($prodSrc, "tip:'Warehouse Stock'")) {
    fail('products grid should show WS with a Warehouse Stock hover tip');
}
if (!str_contains($prodSrc, "bulkNumber") || !str_contains($prodSrc, "key:'spt'")) {
    fail('products grid should offer a bulk SPT editor when multiple rows are selected');
}
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($js, 'bulkNumber') || !str_contains($js, 'grid-bulk-number') || !str_contains($js, 'c.tip')) {
    fail('DataGrid should render column tips and a numeric bulk popup');
}
pass('Products SPT and WS columns, tips, and bulk SPT popup are wired');

/* ----- seed ----- */
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
if (count($grouped) !== 2) {
    fail('expected 2 parent work orders, got ' . count($grouped));
}
$coral = $ocean = null;
foreach ($grouped as $w) {
    if ($w['title'] === 'Coral Reef') {
        $coral = $w;
    }
    if ($w['title'] === 'Deep Ocean') {
        $ocean = $w;
    }
}
if (!$coral || (int)$coral['qty'] !== 13) {
    fail('parent Coral Reef should keep the client-ordered qty of 13');
}
if (count($coral['trays']) !== 2) {
    fail('Coral Reef 13 / SPT 10 should create 2 trays, got ' . count($coral['trays']));
}
if ((int)$coral['trays'][0]['qty'] !== 10 || (int)$coral['trays'][1]['qty'] !== 3) {
    fail('trays should be 10 then 3');
}
if ($coral['trays'][0]['title'] !== 'Tray 1' || $coral['trays'][1]['title'] !== 'Tray 2') {
    fail('tray titles should be Tray 1 and Tray 2');
}
if (!$ocean || $ocean['trays']) {
    fail('Deep Ocean qty 8 should not create trays when SPT is 10');
}
pass('work orders keep ordered qty and spawn Tray sub-tasks when qty exceeds SPT');

/* ----- staff override last tray + filled_from_stock ----- */
$shortId = (int)$coral['trays'][1]['id'];
if (!WorkOrders::setItemQty($shortId, 10)) {
    fail('staff should be able to override a tray qty');
}
if (WorkOrders::setItemQty((int)$coral['id'], 99)) {
    fail('parent qty must stay locked to the client order');
}
$got = (int)$pdo->query('SELECT qty FROM work_orders WHERE id = ' . $shortId)->fetchColumn();
if ($got !== 10) {
    fail('overridden tray qty should be 10, got ' . $got);
}
$parentQty = (int)$pdo->query('SELECT qty FROM work_orders WHERE id = ' . (int)$coral['id'])->fetchColumn();
if ($parentQty !== 13) {
    fail('parent qty should remain 13 after tray override');
}
WorkOrders::setItemStatus($shortId, 'filled_from_stock');
$st = (string)$pdo->query('SELECT status FROM work_orders WHERE id = ' . $shortId)->fetchColumn();
if ($st !== 'filled_from_stock') {
    fail('tray status filled_from_stock did not persist');
}
WorkOrders::setItemStatus((int)$ocean['id'], 'filled_from_stock');
pass('staff can override tray SPT totals and set Filled from stock');

/* ----- approve credits warehouse excess from completed trays ----- */
WorkOrders::setItemStatus((int)$coral['trays'][0]['id'], 'complete');
WorkOrders::setItemStatus((int)$coral['id'], 'complete');
$ready = WorkOrders::readyForApproval();
if (count($ready) !== 1) {
    fail('filled_from_stock should count as done for approval');
}
if (!WorkOrders::approveOrder($oid)) {
    fail('approve should succeed when parents and trays are complete or filled from stock');
}
$ws = (int)$pdo->query('SELECT warehouse_stock FROM products WHERE id = ' . $p1)->fetchColumn();
// produced complete trays = 10 (tray 1); tray 2 filled_from_stock ignored; required 13; excess 0
if ($ws !== 0) {
    fail('no excess should be credited when completed trays are 10 against an order of 13, got WS ' . $ws);
}
$ws2 = (int)$pdo->query('SELECT warehouse_stock FROM products WHERE id = ' . $p2)->fetchColumn();
if ($ws2 !== 2) {
    fail('Deep Ocean had no trays so WS should stay at the starting 2, got ' . $ws2);
}
pass('approval treats Filled from stock as done and does not credit unfinished excess');

/* ----- excess production credits WS ----- */
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'pending', 'pending', 0)")
    ->execute([$clientId]);
$oid2 = (int)$pdo->lastInsertId();
$li->execute([$oid2, $p1, 'Coral Reef', 13, 1560, 20280]);
$pdo->prepare("UPDATE orders SET status='provisioning' WHERE id=?")->execute([$oid2]);
WorkOrders::onStatusChange($oid2, 'pending', 'provisioning');
$g2 = WorkOrders::listGrouped();
$c2 = null;
foreach ($g2 as $w) {
    if ((int)$w['order_id'] === $oid2 && $w['title'] === 'Coral Reef') {
        $c2 = $w;
    }
}
if (!$c2 || count($c2['trays']) !== 2) {
    fail('second order should also split Coral Reef into 2 trays');
}
WorkOrders::setItemQty((int)$c2['trays'][1]['id'], 10);
foreach (array_merge([$c2], $c2['trays']) as $row) {
    WorkOrders::setItemStatus((int)$row['id'], 'complete');
}
if (!WorkOrders::approveOrder($oid2)) {
    fail('second approve failed');
}
$ws = (int)$pdo->query('SELECT warehouse_stock FROM products WHERE id = ' . $p1)->fetchColumn();
if ($ws !== 7) {
    fail('completed trays 10+10 against order 13 should credit WS +7, got ' . $ws);
}
pass('completed trays that over-produce credit Warehouse Stock by the excess');

/* ----- schedule dates + nav ----- */
$today = date('Y-m-d');
if (!WorkOrders::setSchedule((int)$c2['id'], $today, date('Y-m-d', strtotime('+3 days')))) {
    fail('setSchedule should accept YYYY-MM-DD dates');
}
$saved = $pdo->query('SELECT starts_at, ends_at FROM work_orders WHERE id = ' . (int)$c2['id'])->fetch();
if ($saved['starts_at'] !== $today) {
    fail('schedule start did not persist');
}
$idx = file_get_contents(APP_ROOT . '/public/index.php');
if (!str_contains($idx, "admin/work-orders/schedule")) {
    fail('schedule route missing');
}
$side = file_get_contents(APP_ROOT . '/app/Views/admin/_sidebar.php');
if (!str_contains($side, 'Schedule') || !str_contains($side, 'side-sub')) {
    fail('Work orders sidebar should include a Schedule sub-menu');
}
$gantt = file_get_contents(APP_ROOT . '/public/assets/gantt.js');
if (!str_contains($gantt, 'printA3') || !str_contains($gantt, 'selectedOrders') || !str_contains($gantt, 'gantt-handle')) {
    fail('gantt should support order filters, drag/resize, and A3 print');
}
$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($css, 'size: A3 landscape') || !str_contains($css, 'gantt-print-page')) {
    fail('print CSS should paginate the gantt as A3 landscape pages');
}
$schedSrc = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders_schedule.php');
if (!str_contains($schedSrc, 'type="date"') || !str_contains($schedSrc, 'Print A3') || !str_contains($schedSrc, 'Print A4')) {
    fail('schedule page should expose date selectors and A3/A4 print buttons');
}
$woSrc = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders.php');
if (!str_contains($woSrc, 'filled_from_stock') || !str_contains($woSrc, 'wo-qty')) {
    fail('work orders page should list Filled from stock and editable tray qty');
}
pass('schedule submenu, date range, drag/resize, and A3 print are present');

/* ----- new product defaults ----- */
$_SESSION['user'] = ['id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com', 'display_name' => 'Ada Admin'];
Settings::set('product_spt', '12');
$id = (int)$pdo->query("SELECT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (title, price_cents, stock, is_public, min_qty, spt, warehouse_stock) VALUES ('New product', 0, 0, 0, ?, ?, 0)")
    ->execute([Settings::productMinQty(), Settings::productSpt()]);
$created = $pdo->query('SELECT spt, warehouse_stock FROM products ORDER BY id DESC LIMIT 1')->fetch();
if ((int)$created['spt'] !== 12 || (int)$created['warehouse_stock'] !== 0) {
    fail('new products should use settings SPT and WS 0');
}
pass('new products inherit Settings SPT and start WS at 0');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
