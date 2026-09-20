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

$pdo = App\Database::pdo();
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
