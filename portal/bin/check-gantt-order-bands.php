<?php
declare(strict_types=1);

/**
 * Gantt order bands, per-user view prefs, matching-product lines.
 *
 *   php bin/check-gantt-order-bands.php
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

$tmp = sys_get_temp_dir() . '/hd-gantt-bands-' . getmypid() . '.sqlite';
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
use App\UserPrefs;
use App\View;
use App\WorkOrders;

$pdo = Database::pdo();

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('x', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('x', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt) VALUES (?,?,?,?,1,'active',?)")
    ->execute(['Y1', 'Coral Reef', 2400, 10, 10]);
$p1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt) VALUES (?,?,?,?,1,'active',?)")
    ->execute(['Y2', 'Deep Ocean', 2400, 10, 10]);
$p2 = (int)$pdo->lastInsertId();

$li = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)');
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 0)")
    ->execute([$clientId]);
$oid1 = (int)$pdo->lastInsertId();
$li->execute([$oid1, $p1, 'Coral Reef', 13, 1560, 20280]);
$li->execute([$oid1, $p2, 'Deep Ocean', 8, 1560, 12480]);
WorkOrders::syncFromOrder($oid1, false);

$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 0)")
    ->execute([$clientId]);
$oid2 = (int)$pdo->lastInsertId();
$li->execute([$oid2, $p1, 'Coral Reef', 13, 1560, 20280]);
WorkOrders::syncFromOrder($oid2, false);

$today = date('Y-m-d');
$later = date('Y-m-d', strtotime('+5 days'));
$grouped = WorkOrders::listGrouped();
$ids = [];
foreach ($grouped as $w) {
    $ids[] = (int)$w['id'];
    foreach ($w['trays'] ?? [] as $t) {
        $ids[] = (int)$t['id'];
    }
}
if (count($ids) < 4) {
    fail('expected parent + tray rows across two orders');
}
$n = WorkOrders::setScheduleBatch([
    ['id' => $ids[0], 'starts_at' => $today, 'ends_at' => $later],
    ['id' => $ids[1], 'starts_at' => $today, 'ends_at' => date('Y-m-d', strtotime('+2 days'))],
]);
if ($n !== 2) {
    fail('setScheduleBatch should persist 2 rows, got ' . $n);
}
$saved = $pdo->query('SELECT starts_at, ends_at FROM work_orders WHERE id = ' . $ids[0])->fetch();
if ($saved['starts_at'] !== $today || $saved['ends_at'] !== $later) {
    fail('batch schedule dates did not persist');
}
pass('setScheduleBatch updates several work-order dates at once');

$view = UserPrefs::normalizeGanttView([
    'visible_orders' => [$oid1],
    'collapsed_orders' => [(string)$oid2],
    'collapsed_parents' => [$ids[0]],
    'match_lines' => 1,
    'from' => $today,
    'to' => 'nope',
    'junk' => true,
]);
if ($view['visible_orders'] !== [$oid1] || $view['collapsed_orders'] !== [$oid2] || !$view['match_lines'] || $view['to'] !== null) {
    fail('normalizeGanttView should keep ints/bools and drop bad dates');
}
UserPrefs::set($adminId, UserPrefs::GANTT_VIEW, $view);
$again = UserPrefs::normalizeGanttView(UserPrefs::getJson($adminId, UserPrefs::GANTT_VIEW, []));
if ($again['visible_orders'] !== [$oid1] || $again['collapsed_parents'] !== [(int)$ids[0]]) {
    fail('gantt view prefs should persist per user');
}
UserPrefs::set($adminId + 99, UserPrefs::GANTT_VIEW, UserPrefs::normalizeGanttView(['match_lines' => false, 'visible_orders' => [$oid2]]));
$other = UserPrefs::normalizeGanttView(UserPrefs::getJson($adminId + 99, UserPrefs::GANTT_VIEW, []));
if ($other['visible_orders'] === $again['visible_orders'] || !empty($other['match_lines'])) {
    fail('gantt view prefs must stay per-user');
}
pass('gantt display prefs persist per user');

$_SESSION['user'] = [
    'id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com',
    'display_name' => 'Ada Admin', 'first_name' => 'Ada', 'last_name' => 'Admin',
];
$html = View::capture('admin/work_orders_schedule', [
    'title' => 'Work order schedule',
    'active' => 'work-orders-schedule',
    'from' => $today,
    'to' => $later,
    'orders' => WorkOrders::provisioningOrders(),
    'rows' => WorkOrders::listGrouped(),
    'view' => $again,
    'isAdmin' => true,
]);
if (str_contains($html, 'ganttLegend') || str_contains($html, 'Matching product colours stay aligned')) {
    fail('schedule page should not render the old product colour legend');
}
if (!str_contains($html, 'prefKey') || !str_contains($html, UserPrefs::GANTT_VIEW)) {
    fail('schedule payload should include the per-user gantt pref key');
}
if (!str_contains($html, 'id="ganttOrders"') || !str_contains($html, 'Print A3') || !str_contains($html, 'Print A4') || !str_contains($html, 'ganttOverrideRate')) {
    fail('schedule page should still expose order filters, tray-rate override, and print');
}
pass('schedule view drops the product legend and ships saved view prefs');

$js = file_get_contents(APP_ROOT . '/public/assets/gantt.js');
foreach (['wo-filter-name', 'ganttMatchLines', 'Product Matching Lines', 'syncMatchLines', 'collapsedOrders', 'selectedMove', 'schedule_batch', 'LABEL_MAX = 30', 'printMode', 'kind === "order"', 'ORDER_HUES'] as $needle) {
    if (!str_contains($js, $needle)) {
        fail('gantt.js should include ' . $needle);
    }
}
if (str_contains($js, 'renderLegend')) {
    fail('product-item colour legend helper should be gone');
}
$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($css, 'gantt-match-line') || !str_contains($css, 'wo-filter-name.is-selected')) {
    fail('CSS should style match lines and selected order names');
}
pass('gantt wires order bands, group select, collapse, cropped labels, and live match lines');

$orders = WorkOrders::provisioningOrders();
if (count($orders) !== 2 || (int)$orders[0]['order_id'] === (int)$orders[1]['order_id']) {
    fail('two distinct provisioning orders are required for match-line colour tests');
}
if (trim((string)$orders[0]['client_name']) !== trim((string)$orders[1]['client_name'])) {
    fail('fixture should use two orders from the same customer');
}
pass('same customer can have two distinctly scheduled orders');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
