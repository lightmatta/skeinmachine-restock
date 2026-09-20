<?php
declare(strict_types=1);

/**
 * CLI checks for vendors, vendor-product matching, restock split, and schedule.
 */
$root = dirname(__DIR__);
require $root . '/app/helpers.php';
spl_autoload_register(function (string $class) use ($root): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Run php bin/install.php --seed first.\n");
    exit(1);
}
$config = require $configFile;
App\Database::connect($config);
App\Database::migrate($root . '/migrations/schema.sql');

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$vendors = App\Vendors::all();
expect(count($vendors) >= 3, 'Seed vendors exist');

$products = App\Vendors::products();
expect(count($products) >= 8, 'Seed vendor products exist');

$matched = array_values(array_filter($products, fn($r) => (int)($r['matched_product_id'] ?? 0) > 0));
expect(count($matched) >= 6, 'Vendor products match catalog by SKU');

$coral = App\Vendors::matchCatalogProduct('YRN-MERINO-01', '', 'something else');
expect(($coral['sku'] ?? '') === 'YRN-MERINO-01', 'SKU match wins over title');

$title = App\Vendors::matchCatalogProduct('', '', 'Merino Sock — Coral Reef');
expect(($title['sku'] ?? '') === 'YRN-MERINO-01', 'Normalised title match works');

$groups = App\RestockOrders::grouped();
expect($groups !== [], 'Restock groups are produced');

$byName = [];
foreach ($groups as $g) {
    $byName[$g['vendor_name']] = $g;
}
expect(isset($byName['House Dye Wholesale']), 'House Dye Wholesale has a restock group');
$hd = $byName['House Dye Wholesale'] ?? ['fulfillable' => [], 'unfulfillable' => []];
$hdFul = array_column($hd['fulfillable'], 'sku');
$hdUn = array_column($hd['unfulfillable'], 'sku');
expect(in_array('YRN-MERINO-01', $hdFul, true), 'Coral Reef is fulfillable (vendor stock > 0)');
expect(in_array('YRN-DK-01', $hdUn, true), 'Alpaca DK is unfulfillable (vendor stock 0)');
expect(!in_array('YRN-MERINO-02', array_merge($hdFul, $hdUn), true), 'Deep Ocean is above min and omitted');

$fy = $byName['Fleurieu Yarn Co'] ?? ['fulfillable' => [], 'unfulfillable' => []];
expect(in_array('YRN-WORSTED-01', array_column($fy['fulfillable'], 'sku'), true), 'Worsted is fulfillable');
expect(in_array('YRN-LACE-01', array_column($fy['unfulfillable'], 'sku'), true), 'Silk lace is unfulfillable');

$coralLine = null;
foreach ($hd['fulfillable'] as $line) {
    if ($line['sku'] === 'YRN-MERINO-01') {
        $coralLine = $line;
    }
}
expect($coralLine !== null && (int)$coralLine['recommend_qty'] === 32, 'Recommend qty is goal minus stock (36-4)');

$reports = App\RestockOrders::goalReports();
expect($reports !== [], 'Goal restock reports are produced');
$repByName = [];
foreach ($reports as $g) {
    $repByName[$g['vendor_name']] = $g;
}
expect(isset($repByName['House Dye Wholesale']), 'House Dye Wholesale has a goal report');
$hdRepSkus = array_column($repByName['House Dye Wholesale']['lines'] ?? [], 'sku');
expect(in_array('YRN-MERINO-02', $hdRepSkus, true), 'Deep Ocean is below goal and appears on the portal report');
expect(in_array('YRN-MERINO-01', $hdRepSkus, true), 'Coral Reef is below goal and appears on the portal report');
$ocean = null;
foreach ($repByName['House Dye Wholesale']['lines'] as $line) {
    if ($line['sku'] === 'YRN-MERINO-02') {
        $ocean = $line;
    }
}
expect($ocean !== null && (int)$ocean['need_qty'] === 10, 'Need qty is goal minus stock (30-20)');
expect($ocean !== null && (string)$ocean['product_id'] === (string)$ocean['catalog_id'], 'ProductID falls back to catalog id');
$text = App\RestockOrders::reportText($repByName['House Dye Wholesale']);
expect(str_contains($text, 'Restock request — House Dye Wholesale'), 'Copy text names the vendor');
expect(str_contains($text, 'SKU: YRN-MERINO-01'), 'Copy text includes SKU');
expect(str_contains($text, 'Quantity to order:'), 'Copy text includes order qty');

$headerSrc = file_get_contents($root . '/app/Views/layouts/_header.php');
expect(!str_contains($headerSrc, 'Apply for a wholesale'), 'Header has no wholesale apply button');
expect(!str_contains($headerSrc, "url('page'"), 'Header has no About/Contact pages');
expect(!str_contains($headerSrc, 'nav-cart'), 'Header has no shopping cart');

$sidebarSrc = file_get_contents($root . '/app/Views/admin/_sidebar.php');
expect(!str_contains($sidebarSrc, 'admin/bundles'), 'Admin sidebar has no Bundles');

$usersSrc = file_get_contents($root . '/app/Views/admin/users.php');
expect(!str_contains($usersSrc, 'Pending applications'), 'Users page has no pending applications');
expect(!str_contains($usersSrc, 'Approve as wholesale'), 'Users page has no wholesale approval');
expect(!str_contains($usersSrc, 'tray_rate'), 'Users grid has no tray rate');
expect(!str_contains($usersSrc, 'ignore_min'), 'Users grid has no ignore min');
expect(!str_contains($usersSrc, 'discount_percent'), 'Users grid has no discount');
expect(str_contains($usersSrc, "['staff','admin']") || str_contains($usersSrc, "['staff']"), 'Users roles are staff/admin only');

$prodSrc = file_get_contents($root . '/app/Views/admin/products.php');
expect(str_contains($prodSrc, 'bulkNumber'), 'Products grid has bulk number edits');
expect(str_contains($prodSrc, "key:'min_qty'"), 'Products bulk can set min');
expect(str_contains($prodSrc, "key:'goal_qty'"), 'Products bulk can set goal');

$loginSrc = file_get_contents($root . '/app/Views/auth/login.php');
expect(!str_contains($loginSrc, 'Apply here'), 'Login has no wholesale apply link');

$homeSrc = file_get_contents($root . '/app/Views/public/home.php');
expect(str_contains($homeSrc, 'Vendor restock reports'), 'Home is restock reports');
expect(!str_contains($homeSrc, 'Search catalog'), 'Home does not show the product catalog');
expect(str_contains($homeSrc, 'Copy as text'), 'Each report has copy as text');

$pdo = App\Database::pdo();
$roles = $pdo->query("SELECT DISTINCT role FROM users WHERE id > 0")->fetchAll(PDO::FETCH_COLUMN);
foreach ($roles as $role) {
    expect(in_array($role, ['staff', 'admin'], true), "User role '$role' is staff or admin");
}

$userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
foreach (['tray_rate', 'discount_percent', 'ignore_min_quantities'] as $gone) {
    expect(!in_array($gone, $userCols, true), "users.$gone column is removed");
}

$cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
foreach (['spt', 'warehouse_stock', 'colours'] as $hidden) {
    expect(in_array($hidden, $cols, true), "Legacy column $hidden remains in schema");
}
expect(in_array('goal_qty', $cols, true), 'goal_qty column exists');
expect(in_array('vendor_id', $cols, true), 'vendor_id column exists');

$sched = App\RestockOrders::listGrouped();
expect($sched !== [], 'Schedule rows materialise for restock lines');
$ids = array_map(fn($r) => (int)$r['order_id'], App\RestockOrders::provisioningOrders());
$auto = App\RestockOrders::autoSchedule($ids, []);
expect(!empty($auto['ok']) && (int)$auto['updated'] > 0, 'Auto-schedule places vendor lines');

$pdo->prepare("UPDATE vendor_products SET status = 'inactive' WHERE sku = 'YRN-MERINO-01'")->execute();
$after = App\RestockOrders::grouped();
$hd2 = null;
foreach ($after as $g) {
    if ($g['vendor_name'] === 'House Dye Wholesale') {
        $hd2 = $g;
    }
}
$skus2 = array_merge(array_column($hd2['fulfillable'] ?? [], 'sku'), array_column($hd2['unfulfillable'] ?? [], 'sku'));
expect(!in_array('YRN-MERINO-01', array_column($hd2['fulfillable'] ?? [], 'sku'), true), 'Inactive vendor product is not treated as in-stock');

echo $fail === 0 ? "\nAll restock checks passed.\n" : "\n$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
