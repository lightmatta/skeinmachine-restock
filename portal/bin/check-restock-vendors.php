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
expect(($repByName['House Dye Wholesale']['label'] ?? '') === 'House Dye Wholesale / Yarn Collection', 'House Dye report label is vendor / collection');
$adelaide = $repByName['Adelaide Notions'] ?? null;
expect($adelaide !== null, 'Adelaide Notions groups both collections into one report');
expect(($adelaide['label'] ?? '') === 'Adelaide Notions / Bowls, Markers', 'Adelaide collections are alphabetical in the label');
expect(count($adelaide['collections'] ?? []) === 2, 'Adelaide report lists both collections');
$text = App\RestockOrders::reportText($repByName['House Dye Wholesale']);
expect(str_contains($text, 'Restock Request Form'), 'Copy text is labelled Restock Request Form');
expect(str_contains($text, 'House Dye Wholesale / Yarn Collection'), 'Copy text names vendor / collection');
expect(str_contains($text, 'SKU: YRN-MERINO-01'), 'Copy text includes SKU');
expect(str_contains($text, 'Current inventory:'), 'Copy text includes current inventory');
expect(str_contains($text, 'Order quantity:'), 'Copy text includes order qty');
expect(str_contains($text, 'Total:'), 'Copy text includes Total');
$blankGroup = [
    'vendor_name' => 'Blank Vendor',
    'label' => 'Blank Vendor / Demo',
    'lines' => [[
        'sku' => '',
        'product_id' => '',
        'title' => 'Nameless item',
        'stock' => 0,
        'need_qty' => 4,
        'goal_qty' => 4,
        'min_qty' => 2,
    ]],
];
$blankText = App\RestockOrders::reportText($blankGroup);
expect(str_contains($blankText, 'SKU: unknown'), 'Copy text uses unknown for a missing SKU');
expect(str_contains($blankText, 'Product ID: unknown'), 'Copy text uses unknown for a missing product ID');
$blankPdf = App\RestockOrders::reportPdf($blankGroup);
expect(str_contains($blankPdf, 'unknown'), 'PDF uses unknown for missing SKU or product ID');
expect(App\RestockOrders::urgencyLevel(0, 10) === 'red', 'Zero inventory is red urgency');
expect(App\RestockOrders::urgencyLevel(5, 10) === 'orange', 'Half of min is orange urgency');
expect(App\RestockOrders::urgencyLevel(10, 10) === 'yellow', 'At min is yellow urgency');
expect(App\RestockOrders::urgencyLevel(12, 10) === '', 'Above min is not an urgency colour');
$attention = App\RestockOrders::attentionReports($reports);
expect(count($attention) <= 3, 'Overview attention list is at most three vendors');
expect(count($attention) >= 1, 'Overview attention list includes vendors below min');
$hotNames = array_map(static fn($g) => (string)$g['vendor_name'], $attention);
expect(in_array('Adelaide Notions', $hotNames, true) || in_array('House Dye Wholesale', $hotNames, true), 'Attention ranking uses vendors with below-min stock');
$pdf = App\RestockOrders::reportPdf($adelaide);
expect(str_starts_with($pdf, '%PDF-1.4'), 'PDF writer emits PDF 1.4');
expect(str_contains($pdf, 'Restock Request Form'), 'PDF is labelled Restock Request Form');
expect(str_contains($pdf, 'Adelaide Notions / Bowls, Markers'), 'PDF uses the grouped vendor / collection label');
expect(str_contains($pdf, 'Total'), 'PDF includes a Total column');
expect(str_contains($pdf, '0 0 0 rg'), 'PDF body text is black');
expect(str_contains($pdf, '0 0 0 RG'), 'PDF table outlines are black');
expect(str_contains(App\RestockOrders::reportFilename($adelaide), 'adelaide-notions'), 'PDF filename slugs the vendor group');

$headerSrc = file_get_contents($root . '/app/Views/layouts/_header.php');
expect(!str_contains($headerSrc, 'Apply for a wholesale'), 'Header has no wholesale apply button');
expect(!str_contains($headerSrc, "url('page'"), 'Header has no About/Contact pages');
expect(!str_contains($headerSrc, 'nav-cart'), 'Header has no shopping cart');
expect(str_contains($headerSrc, "['Reports', url('home')"), 'Header nav is Reports');
expect(!str_contains($headerSrc, "['Home', url('home')"), 'Header nav is no longer Home');
expect(!str_contains($headerSrc, 'Restock orders'), 'Account menu has no Restock orders');
expect(!str_contains($headerSrc, 'Schedule'), 'Account menu has no Schedule');

$sidebarSrc = file_get_contents($root . '/app/Views/admin/_sidebar.php');
expect(!str_contains($sidebarSrc, 'admin/bundles'), 'Admin sidebar has no Bundles');
expect(!str_contains($sidebarSrc, 'admin/vendors'), 'Admin sidebar has no Vendors');
expect(!str_contains($sidebarSrc, 'vendor-products'), 'Admin sidebar has no Vendor products');
expect(!str_contains($sidebarSrc, 'work-orders'), 'Admin sidebar has no Restock orders or Schedule');
expect(str_contains($sidebarSrc, "'Dashboard'"), 'Sidebar includes Dashboard');
expect(str_contains($sidebarSrc, "'Users'"), 'Sidebar includes Users');
expect(str_contains($sidebarSrc, "'Messages'"), 'Sidebar includes Messages');
expect(str_contains($sidebarSrc, "'Products'"), 'Sidebar includes Products');
expect(str_contains($sidebarSrc, "'Sources'"), 'Sidebar includes Sources');
expect(str_contains($sidebarSrc, "'Settings'"), 'Sidebar includes Settings');

$dashSrc = file_get_contents($root . '/app/Views/admin/dashboard.php');
expect(str_contains($dashSrc, 'Needs attention'), 'Overview highlights vendors that need attention');
expect(str_contains($dashSrc, 'attentionReports') || str_contains($dashSrc, 'attention-list'), 'Overview has an attention list');
$dashPos = strpos($sidebarSrc, "'Dashboard'");
$usersPos = strpos($sidebarSrc, "'Users'");
$msgsPos = strpos($sidebarSrc, "'Messages'");
$prodPos = strpos($sidebarSrc, "'Products'");
$srcPos = strpos($sidebarSrc, "'Sources'");
$setPos = strpos($sidebarSrc, "'Settings'");
expect($dashPos < $usersPos && $usersPos < $msgsPos && $msgsPos < $prodPos && $prodPos < $srcPos && $srcPos < $setPos, 'Sidebar order is Dashboard, Users, Messages, Products, Sources, Settings');

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
expect(str_contains($prodSrc, 'vendorFilter'), 'Products grid has select by vendor name');
expect(str_contains($prodSrc, 'Minimum quantity to trigger a restock alert'), 'Min hover is the restock-alert description');
expect(str_contains($prodSrc, 'Ideal restock level, budget/vendor stocks dependent'), 'Goal hover is the ideal restock description');

$loginSrc = file_get_contents($root . '/app/Views/auth/login.php');
expect(!str_contains($loginSrc, 'Apply here'), 'Login has no wholesale apply link');

$homeSrc = file_get_contents($root . '/app/Views/public/home.php');
expect(str_contains($homeSrc, 'Reports'), 'Home screen is titled Reports');
expect(!str_contains($homeSrc, 'Vendor restock reports'), 'Old vendor restock reports heading is gone');
expect(!str_contains($homeSrc, 'Search catalog'), 'Home does not show the product catalog');
expect(str_contains($homeSrc, 'Copy as text'), 'Each report has copy as text');
expect(str_contains($homeSrc, 'pdf-report') && str_contains($homeSrc, 'Restock Request Form PDF'), 'Each report has a PDF print icon');
expect(str_contains($homeSrc, 'Order Quantity'), 'Reports column is Order Quantity');
expect(str_contains($homeSrc, 'Current Inventory Stock Level'), 'Inv column hover explains current inventory');
expect(str_contains($homeSrc, '>Total<') || str_contains($homeSrc, 'Inv + Order Quantity'), 'Reports has a Total column');
expect(str_contains($homeSrc, 'col-name'), 'Product name uses a truncating column');
expect(str_contains($homeSrc, 'td.col-name'), 'Full product name popover is bound to name cells, not the header');
expect(str_contains($homeSrc, 'copyPreviewModal'), 'Copy as text opens a preview');
expect(str_contains($homeSrc, 'urgency-'), 'Report rows can take urgency colour classes');
expect(!str_contains($homeSrc, 'Desired Goal'), 'Old goal-minus-stock column title is gone');

$cssSrc = file_get_contents($root . '/public/assets/app.css');
expect(str_contains($cssSrc, 'text-transform: none'), 'Report table headers are not forced uppercase');
expect(str_contains($cssSrc, 'urgency-red'), 'Report urgency red style exists');
expect(str_contains($cssSrc, 'copy-preview-letter'), 'Copy preview is styled as a letter');

$jsSrc = file_get_contents($root . '/public/assets/app.js');
expect(str_contains($jsSrc, 'currentlyAll'), 'Check-all toggles all on or all off');
expect(str_contains($jsSrc, 'vendorFilter'), 'Products grid can filter by vendor');
expect(!str_contains($jsSrc, 'this.selected.clear();'), 'Bulk min/goal does not clear the selection');

$sourcesSrc = file_get_contents($root . '/app/Views/admin/sources.php');
expect(str_contains($sourcesSrc, 'Vendor Name'), 'Sources has Vendor Name');
expect(str_contains($sourcesSrc, 'Source Name'), 'Sources has Source Name');
expect(str_contains($sourcesSrc, 'Collection ID'), 'Sources has Collection ID');
expect(str_contains($sourcesSrc, 'Sync frequency'), 'Sources has sync frequency');
expect(str_contains($sourcesSrc, 'Last sync'), 'Sources has last sync');
expect(str_contains($sourcesSrc, "op:'sync'"), 'Sources has Sync now');

$setSrc = file_get_contents($root . '/app/Views/admin/settings.php');
expect(str_contains($setSrc, 'Allow automated sync schedules'), 'Settings has automated sync checkbox');
expect(str_contains($setSrc, 'name="default_sync_frequency_days"'), 'Settings has default sync frequency');
expect(str_contains($setSrc, 'name="report_urgency_colors"'), 'Settings can turn report urgency colours on or off');
expect(!str_contains($setSrc, 'name="shopify_periodic_sync"'), 'Settings no longer has Periodic Sync');
expect(!str_contains($setSrc, 'name="shopify_collection_id"'), 'Settings no longer has a global Collection ID');

$empty = App\Sources::create();
$refused = App\Sources::syncNow($empty);
expect(($refused['error'] ?? '') === 'no_collection', 'Sync now refuses an empty Collection ID');
$emptyRow = App\Sources::find($empty);
expect((int)($emptyRow['sync_frequency_days'] ?? 0) === 1, 'New source uses the 1-day default sync frequency');
App\Settings::set('default_sync_frequency_days', '4');
$custom = App\Sources::create();
$customRow = App\Sources::find($custom);
expect((int)($customRow['sync_frequency_days'] ?? 0) === 4, 'New source uses the Settings default when changed');
App\Settings::set('default_sync_frequency_days', '1');
App\Sources::delete($custom);
App\Sources::delete($empty);

App\Settings::set('shopify_domain', 'house-dye.myshopify.com');
App\Settings::set('shopify_client_id', 'cid-123');
App\Settings::set('shopify_client_secret', 'csec-456');
App\ShopifyService::forgetCachedToken();
$calls = [];
App\ShopifyService::$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, '/admin/oauth/access_token')) {
        return [200, json_encode(['access_token' => 'tok', 'expires_in' => 86399])];
    }
    if (str_contains($url, 'collections/')) {
        return [200, json_encode(['collection' => ['title' => 'Yarn Collection']] )];
    }
    return [404, '{}'];
};
$sid = App\Sources::create();
App\Sources::update($sid, ['collection_id' => '1001001', 'collection_name' => '']);
$filled = App\Sources::find($sid);
expect(trim((string)($filled['collection_name'] ?? '')) === 'Yarn Collection', 'Empty Source Name is filled from Shopify collection title');
App\Sources::update($sid, ['collection_name' => 'Keep this name']);
App\Sources::fillNameIfEmpty($sid);
$kept = App\Sources::find($sid);
expect((string)$kept['collection_name'] === 'Keep this name', 'A user-set Source Name is not overwritten');
App\ShopifyService::$transport = null;
App\Sources::delete($sid);

$spread = App\Sources::spreadTimes('2026-09-20', 3);
expect($spread === ['2026-09-20 04:00:00', '2026-09-20 12:00:00', '2026-09-20 20:00:00'], 'Same-day syncs are spread evenly across 24 hours');
App\Settings::set('allow_automated_sync', '1');
App\Sources::rescheduleAll(strtotime('2026-09-20 10:00:00'));
$scheduled = App\Database::pdo()->query(
    "SELECT next_sync_at FROM sources WHERE archived = 0 AND TRIM(collection_id) <> '' AND date(next_sync_at) = date(next_sync_at) ORDER BY next_sync_at"
)->fetchAll(PDO::FETCH_COLUMN);
expect(count($scheduled) >= 2, 'Automation assigns next_sync_at when enabled');
$sameDay = [];
foreach ($scheduled as $ts) {
    $day = substr((string)$ts, 0, 10);
    $sameDay[$day][] = (string)$ts;
}
$spreadOk = true;
foreach ($sameDay as $times) {
    if (count($times) < 2) {
        continue;
    }
    $uniq = array_unique($times);
    if (count($uniq) !== count($times)) {
        $spreadOk = false;
    }
}
expect($spreadOk, 'Sources due on the same day receive distinct clock times');
App\Settings::set('allow_automated_sync', '0');
App\Sources::rescheduleAll();
$cleared = (int)App\Database::pdo()->query(
    "SELECT COUNT(*) FROM sources WHERE next_sync_at IS NOT NULL AND next_sync_at <> ''"
)->fetchColumn();
expect($cleared === 0, 'Turning automation off clears scheduled sync times');

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
