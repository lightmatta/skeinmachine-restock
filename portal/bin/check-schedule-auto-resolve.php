<?php
declare(strict_types=1);

/**
 * Auto-resolve tray-rate conflicts and Auto-schedule packing rules.
 *
 *   php bin/check-schedule-auto-resolve.php
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

$gantt = file_get_contents(APP_ROOT . '/public/assets/gantt.js');
$sched = file_get_contents(APP_ROOT . '/app/Views/admin/work_orders_schedule.php');
$admin = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');

if (str_contains($gantt, 'window.alert') || str_contains($gantt, 'warnConflicts')) {
    fail('gantt should offer a resolve modal instead of window.alert');
}
if (!str_contains($gantt, 'offerResolveConflicts') || !str_contains($gantt, 'runAutoSchedule') || !str_contains($gantt, 'Undo auto-schedule')) {
    fail('gantt should offer resolve + Auto-schedule undo');
}
if (!str_contains($gantt, 'is-conflict') || !str_contains($gantt, 'overrideRate')) {
    fail('conflict highlights must stay independent of the override checkbox');
}
if (!str_contains($sched, 'ganttAutoSchedule') || !str_contains($sched, 'ganttConflictModal')) {
    fail('schedule page should include Auto-schedule and the conflict modal');
}
if (!str_contains($sched, 'Automatically resolve scheduling conflicts just for the items that are affected by this issue')) {
    fail('resolve button must use the requested offer copy');
}
if (!str_contains($admin, "op === 'auto_schedule'") || !str_contains($admin, "op === 'restore_schedule'")) {
    fail('work_orders API should expose auto_schedule and restore_schedule');
}

$tmp = sys_get_temp_dir() . '/hd-auto-resolve-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);
pass('schedule UI offers resolve-affected and Auto-schedule undo instead of an alert');

use App\Database;
use App\WorkOrders;

$pdo = Database::pdo();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('x', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, tray_rate) VALUES (?,?,?,?,?,?,?)")
    ->execute(['staff@example.com', password_hash('x', PASSWORD_BCRYPT), 'staff', 'active', 'Sam', 'Staff', 3]);
$staffId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt) VALUES (?,?,?,?,1,'active',?)")
    ->execute(['CORAL', 'Coral Reef', 2400, 20, 10]);
$pCoral = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, spt) VALUES (?,?,?,?,1,'active',?)")
    ->execute(['OCEAN', 'Deep Ocean', 2400, 20, 10]);
$pOcean = (int)$pdo->lastInsertId();

function makeOrder(int $clientId, array $lines): int
{
    $pdo = Database::pdo();
    $pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?, 'provisioning', 'pending', 0)")
        ->execute([$clientId]);
    $oid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?)');
    foreach ($lines as $line) {
        $ins->execute([$oid, $line['product_id'], $line['title'], $line['qty'], 1560, 1560 * $line['qty']]);
    }
    WorkOrders::syncFromOrder($oid, false);
    return $oid;
}

function traysForOrder(int $orderId): array
{
    $out = [];
    foreach (WorkOrders::listGrouped() as $w) {
        if ((int)$w['order_id'] !== $orderId) {
            continue;
        }
        foreach ($w['trays'] as $t) {
            $out[] = $t;
        }
    }
    return $out;
}

function datesById(array $trays): array
{
    $out = [];
    foreach ($trays as $t) {
        $out[(int)$t['id']] = [(string)$t['starts_at'], (string)$t['ends_at']];
    }
    return $out;
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$day2 = date('Y-m-d', strtotime('+2 day'));

$oidFit = makeOrder($clientId, [
    ['product_id' => $pCoral, 'title' => 'Coral Reef', 'qty' => 25],
]);
WorkOrders::assignStaffToOrder($oidFit, $staffId);
$fit = traysForOrder($oidFit);
if (count($fit) < 3) {
    fail('25 / SPT 10 should spawn 3 trays, got ' . count($fit));
}
$fitDays = array_unique(array_column($fit, 'starts_at'));
if (count($fitDays) !== 1 || $fitDays[0] !== $today) {
    fail('a 3-tray work order at rate 3 should finish in one day, got ' . json_encode($fitDays));
}
foreach ($fit as $t) {
    if ($t['starts_at'] !== $t['ends_at']) {
        fail('packed trays must stay 1-day boxes');
    }
}
pass('work order with multiple trays stays on one day when the tray rate allows');

$oidOver = makeOrder($clientId, [
    ['product_id' => $pOcean, 'title' => 'Deep Ocean', 'qty' => 35],
]);
WorkOrders::assignStaffToOrder($oidOver, $staffId);
$overTrays = traysForOrder($oidOver);
$overDays = [];
foreach ($overTrays as $t) {
    $overDays[$t['starts_at']] = ($overDays[$t['starts_at']] ?? 0) + 1;
}
if (max($overDays) > 3) {
    fail('overflow should never exceed tray rate 3, got ' . json_encode($overDays));
}
if (count($overDays) < 2) {
    fail('4 trays at rate 3 should overflow to the next day, got ' . json_encode($overDays));
}
$keys = array_keys($overDays);
sort($keys);
$expected = [$tomorrow, $day2];
if ($keys !== $expected && $keys !== [$today, $tomorrow]) {
    // Coral already filled today (3/3), so Ocean should start tomorrow.
    if ($keys !== [$tomorrow, $day2]) {
        fail('overflow should be consecutive days after reserved load, got ' . json_encode($overDays));
    }
}
pass('work orders larger than the tray rate overflow to the next consecutive day');

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, tray_rate) VALUES (?,?,?,?,?,?,?)")
    ->execute(['pat@example.com', password_hash('x', PASSWORD_BCRYPT), 'staff', 'active', 'Pat', 'Packer', 4]);
$patId = (int)$pdo->lastInsertId();

$oidA = makeOrder($clientId, [
    ['product_id' => $pCoral, 'title' => 'Coral Reef', 'qty' => 15],
    ['product_id' => $pOcean, 'title' => 'Deep Ocean', 'qty' => 15],
]);
$oidB = makeOrder($clientId, [
    ['product_id' => $pCoral, 'title' => 'Coral Reef', 'qty' => 15],
]);
WorkOrders::assignStaffToOrder($oidA, $patId);
WorkOrders::assignStaffToOrder($oidB, $patId);
$packed = WorkOrders::autoSchedule([$oidA, $oidB], []);
if (!$packed['ok']) {
    fail('autoSchedule should succeed for selected orders');
}
$coralDays = [];
$oceanDays = [];
$patLoad = [];
foreach (WorkOrders::listGrouped() as $w) {
    if ((int)$w['staff_user_id'] !== $patId) {
        continue;
    }
    foreach ($w['trays'] as $t) {
        $patLoad[$t['starts_at']] = ($patLoad[$t['starts_at']] ?? 0) + 1;
        if ((int)$t['product_id'] === $pCoral) {
            $coralDays[$t['starts_at']] = ($coralDays[$t['starts_at']] ?? 0) + 1;
        }
        if ((int)$t['product_id'] === $pOcean) {
            $oceanDays[$t['starts_at']] = ($oceanDays[$t['starts_at']] ?? 0) + 1;
        }
    }
}
if (count($coralDays) !== 1 || max($coralDays) !== 4) {
    fail('matching Coral trays across orders should share one day at rate 4, got ' . json_encode($coralDays));
}
$coralDay = array_key_first($coralDays);
if (isset($oceanDays[$coralDay])) {
    fail('Ocean should sit on the leftover day so Coral stays aligned, got ocean=' . json_encode($oceanDays));
}
ksort($patLoad);
$patDays = array_keys($patLoad);
$span = (strtotime($patDays[count($patDays) - 1]) - strtotime($patDays[0])) / 86400;
if ($span > 1) {
    fail('6 trays at rate 4 should pack into 2 sequential days, got ' . json_encode($patLoad));
}
if (max($patLoad) > 4) {
    fail('auto-schedule left a day over rate 4: ' . json_encode($patLoad));
}
if (WorkOrders::scheduleConflicts()) {
    fail('auto-schedule should clear tray-rate conflicts: ' . json_encode(WorkOrders::scheduleConflicts()));
}
pass('matching products align and selected orders pack tight to the tray rate');

$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name, tray_rate) VALUES (?,?,?,?,?,?,?)")
    ->execute(['lee@example.com', password_hash('x', PASSWORD_BCRYPT), 'staff', 'active', 'Lee', 'Line', 2]);
$leeId = (int)$pdo->lastInsertId();
$oidKeep = makeOrder($clientId, [
    ['product_id' => $pCoral, 'title' => 'Coral Reef', 'qty' => 25],
]);
$oidFar = makeOrder($clientId, [
    ['product_id' => $pOcean, 'title' => 'Deep Ocean', 'qty' => 15],
]);
WorkOrders::assignStaffToOrder($oidKeep, $leeId);
WorkOrders::assignStaffToOrder($oidFar, $leeId);
$keepTrays = traysForOrder($oidKeep);
$farTrays = traysForOrder($oidFar);
$farDay = date('Y-m-d', strtotime('+10 day'));
foreach ($farTrays as $t) {
    WorkOrders::setSchedule((int)$t['id'], $farDay, $farDay);
}
foreach ($keepTrays as $t) {
    WorkOrders::setSchedule((int)$t['id'], $today, $today);
}
$beforeFar = datesById(traysForOrder($oidFar));
$conflicts = WorkOrders::scheduleConflicts();
if (!$conflicts) {
    fail('three Coral trays on one day at rate 2 should conflict');
}
$conflictIds = [];
foreach ($conflicts as $c) {
    if ((int)$c['staff_user_id'] === $leeId) {
        foreach ($c['ids'] as $id) {
            $conflictIds[] = (int)$id;
        }
    }
}
$resolved = WorkOrders::autoSchedule([], $conflictIds);
if (!$resolved['ok'] || !$resolved['snapshot']) {
    fail('conflict resolve should snapshot and rewrite affected items');
}
$afterFar = datesById(traysForOrder($oidFar));
if ($beforeFar !== $afterFar) {
    fail('resolve should not move trays that are not part of the conflict');
}
$afterKeep = traysForOrder($oidKeep);
$keepLoad = [];
foreach ($afterKeep as $t) {
    $keepLoad[$t['starts_at']] = ($keepLoad[$t['starts_at']] ?? 0) + 1;
}
if (max($keepLoad) > 2) {
    fail('resolved Coral trays should respect rate 2, got ' . json_encode($keepLoad));
}
foreach (WorkOrders::scheduleConflicts() as $c) {
    if ((int)$c['staff_user_id'] === $leeId) {
        fail('resolve should clear Lee\'s conflict: ' . json_encode($c));
    }
}
pass('resolve moves only conflict-affected work-order trays');

$snap = $resolved['snapshot'];
$n = WorkOrders::restoreDates($snap);
if ($n < 1) {
    fail('restoreDates should write the snapshot back');
}
$restored = datesById(traysForOrder($oidKeep));
$want = [];
foreach ($snap as $it) {
    $want[(int)$it['id']] = [(string)$it['starts_at'], (string)$it['ends_at']];
}
foreach ($want as $id => $pair) {
    if (!isset($restored[$id]) || $restored[$id] !== $pair) {
        fail('undo should restore tray #' . $id . ' to ' . json_encode($pair) . ', got ' . json_encode($restored[$id] ?? null));
    }
}
if (!WorkOrders::scheduleConflicts()) {
    fail('restoring the stacked snapshot should bring the conflict back');
}
pass('restore_schedule returns Auto-schedule work to the original dates');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "schedule auto-resolve / auto-schedule checks passed\n";
