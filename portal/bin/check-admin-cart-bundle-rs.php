<?php
declare(strict_types=1);

/**
 * Admin storefront cart, Create Bundle from an order, product column labels,
 * Retail vs Wholesale Status, and catalog visibility via is_public.
 *
 *   php bin/check-admin-cart-bundle-rs.php
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

$tmp = sys_get_temp_dir() . '/hd-admin-cart-rs-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Auth;
use App\Catalog;
use App\Controllers\AdminController;
use App\Database;
use App\ShopifyCsv;
use App\ShopifyService;
use App\View;

$pdo = Database::pdo();

/* ----- column labels / order / tips ----- */
$prodSrc = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (!str_contains($prodSrc, "label:'RS'") || !str_contains($prodSrc, "tip:'Retail Stock'")) {
    fail('Stock column should be RS with a Retail Stock hover tip');
}
if (preg_match("/label:'Stock'/", $prodSrc)) {
    fail('products grid still uses the Stock heading');
}
$rsPos = strpos($prodSrc, "key:'stock'");
$wsPos = strpos($prodSrc, "key:'warehouse_stock'");
$minPos = strpos($prodSrc, "key:'min_qty'");
if ($rsPos === false || $wsPos === false || $minPos === false || !($rsPos < $wsPos && $wsPos < $minPos)) {
    fail('WS should sit immediately after RS and before Min');
}
if (!str_contains($prodSrc, "tip:'Minimum Order Quantity'")) {
    fail('Min hover should read Minimum Order Quantity');
}
if (!str_contains($prodSrc, "label:'Retail Status'") || str_contains($prodSrc, "key:'status', label:'Status'")) {
    fail('Status column should be renamed Retail Status');
}
if (str_contains($prodSrc, 'editable:true') && preg_match("/key:'status'[^\\n]*editable:true/", $prodSrc)) {
    fail('Retail Status must not be editable');
}
if (!str_contains($prodSrc, "label:'Wholesale Status'") || str_contains($prodSrc, "label:'Public'")) {
    fail('Public column should be renamed Wholesale Status');
}
if (!str_contains($prodSrc, "label:'visible'") || !str_contains($prodSrc, "label:'hidden'")) {
    fail('Wholesale Status editor should offer visible / hidden labels');
}
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($js, 'o.label') || !str_contains($js, 'typeof o === "object"')) {
    fail('DataGrid should render object-shaped select options with labels');
}
$adminSrc = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, "'editable' => ['sku', 'title', 'description', 'category', 'price_cents', 'stock', 'min_qty', 'spt', 'warehouse_stock', 'is_public', 'archived', 'colours']")) {
    fail('product editable whitelist must omit status (Retail Status)');
}
if (str_contains($prodSrc, "{key:'status', label:'Set status'")) {
    fail('bulk Set status action should be removed');
}
if (!str_contains($prodSrc, "label:'Set wholesale status'")) {
    fail('bulk action should set wholesale status only');
}
pass('products grid uses RS / WS / Min / Retail Status / Wholesale Status');

$ordersSrc = file_get_contents(APP_ROOT . '/app/Views/admin/orders.php');
if (!str_contains($ordersSrc, "op:'create_bundle'") || !str_contains($ordersSrc, 'Create Bundle')) {
    fail('orders grid should expose a Create Bundle row action');
}
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($js, 'rowActions') || !str_contains($js, 'customAction') || !str_contains($js, 'act-custom')) {
    fail('DataGrid should render custom rowActions');
}
$layout = file_get_contents(APP_ROOT . '/app/Views/layouts/app.php');
if (!str_contains($layout, "Icons::get('bundle', 15)")) {
    fail('layout should expose the bundle icon for Create Bundle');
}
pass('Create Bundle action and DataGrid rowActions are wired');

/* ----- shopper roles ----- */
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['admin@example.com', password_hash('admin12345', PASSWORD_BCRYPT), 'admin', 'active', 'Ada', 'Admin']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name, last_name) VALUES (?,?,?,?,?,?)")
    ->execute(['shop@example.com', password_hash('shop12345', PASSWORD_BCRYPT), 'wholesale', 'active', 'Wendy', 'Wholesale']);
$clientId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, role, status, first_name) VALUES (?,?,?,?,?)")
    ->execute(['staff@example.com', password_hash('staff12345', PASSWORD_BCRYPT), 'staff', 'active', 'Sam']);
$staffId = (int)$pdo->lastInsertId();

$_SESSION['user'] = ['id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com', 'display_name' => 'Ada Admin'];
if (!Auth::canOrder()) {
    fail('database admin should be able to shop');
}
$_SESSION['user'] = ['id' => $clientId, 'role' => 'wholesale', 'email' => 'shop@example.com', 'display_name' => 'Wendy'];
if (!Auth::canOrder()) {
    fail('wholesale client should be able to shop');
}
$_SESSION['user'] = ['id' => $staffId, 'role' => 'staff', 'email' => 'staff@example.com', 'display_name' => 'Sam'];
if (Auth::canOrder()) {
    fail('staff must not shop the storefront');
}
$_SESSION['user'] = ['id' => 0, 'role' => 'superuser', 'email' => 'root@example.com', 'display_name' => 'Super'];
if (!Auth::canOrder()) {
    fail('config superuser (id 0) should shop like other users');
}
if (Auth::shopperId() !== 0) {
    fail('superuser shopper id should stay 0');
}
if ($pdo->query('SELECT id FROM users WHERE id = 0')->fetch() === false) {
    fail('superuser checkout needs a sentinel users row at id 0');
}
$headerSrc = file_get_contents(APP_ROOT . '/app/Views/layouts/_header.php');
if (!str_contains($headerSrc, 'Auth::canOrder()') || !str_contains($headerSrc, "['cart', 'cart', 'Cart']")) {
    fail('admin account menu should include Cart / My orders when the admin can shop');
}
$wc = file_get_contents(APP_ROOT . '/app/Controllers/WholesaleController.php');
if (!str_contains($wc, 'requireShopper()') || !str_contains($wc, 'Auth::shopperId()')) {
    fail('checkout should require a shopper and bill the Super Admin sentinel');
}
pass('admin and superuser can shop; staff cannot');

/* ----- catalog visibility uses Wholesale Status, not Retail Status ----- */
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status) VALUES (?,?,?,?,?,?)")
    ->execute(['VIS', 'Visible Yarn', 2000, 5, 1, 'inactive']);
$visId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status) VALUES (?,?,?,?,?,?)")
    ->execute(['HID', 'Hidden Yarn', 1800, 4, 0, 'active']);
$hidId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status) VALUES (?,?,?,?,?,?)")
    ->execute(['ARC', 'Archived Yarn', 1000, 1, 1, 'active']);
$arcId = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE products SET archived = 1 WHERE id = ?')->execute([$arcId]);

$guestIds = array_map('intval', array_column(Catalog::products(false), 'id'));
if (!in_array($visId, $guestIds, true)) {
    fail('inactive Retail Status + visible Wholesale Status should still list on the storefront');
}
if (in_array($hidId, $guestIds, true) || in_array($arcId, $guestIds, true)) {
    fail('hidden or archived products leaked into the public catalog');
}
$_SESSION['user'] = ['id' => $adminId, 'role' => 'admin', 'email' => 'admin@example.com', 'display_name' => 'Ada Admin'];
$adminIds = array_map('intval', array_column(Catalog::products(true), 'id'));
if (!in_array($visId, $adminIds, true) || !in_array($hidId, $adminIds, true)) {
    fail('admin homepage should list every non-archived product including hidden ones');
}
if (in_array($arcId, $adminIds, true)) {
    fail('admin listing should still omit archived products');
}
$ctx = Catalog::listingContext();
if (empty($ctx['wholesale']) || empty($ctx['ignoreMin'])) {
    fail('admin listing should enable Add to cart and ignore min qty');
}
$adminTitles = array_map(static fn($p) => $p['title'], $ctx['products']);
if (!in_array('Hidden Yarn', $adminTitles, true)) {
    fail('admin listing context omitted a hidden product');
}
$cards = View::capture('_catalog_cards', $ctx);
if (!str_contains($cards, 'Add to cart') || !str_contains($cards, 'Hidden Yarn')) {
    fail('admin catalog cards should offer Add to cart on hidden products');
}

$_SESSION['user'] = ['id' => $clientId, 'role' => 'wholesale', 'email' => 'shop@example.com', 'display_name' => 'Wendy'];
$wctx = Catalog::listingContext();
$wTitles = array_map(static fn($p) => $p['title'], $wctx['products']);
if (in_array('Hidden Yarn', $wTitles, true)) {
    fail('wholesale homepage still lists a product with Wholesale Status hidden');
}
if (!in_array('Visible Yarn', $wTitles, true)) {
    fail('wholesale homepage hid a product that is wholesale-visible');
}
$apiSrc = file_get_contents(APP_ROOT . '/app/Controllers/ApiController.php');
if (!str_contains($apiSrc, 'is_public = 1 AND archived = 0') || str_contains($apiSrc, "status = 'active'")) {
    fail('wholesale API product list must use Wholesale Status, not Retail Status');
}
pass('Wholesale Status drives catalog visibility site-wide');

/* ----- Create Bundle from an order ----- */
$pdo->prepare("INSERT INTO orders (user_id, status, payment_status, total_cents) VALUES (?,?,?,?)")
    ->execute([$adminId, 'pending', 'pending', 5600]);
$oid = (int)$pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, bundle_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?,?)');
$ins->execute([$oid, $visId, null, 'Visible Yarn', 3, 1300, 3900]);
$ins->execute([$oid, $hidId, null, 'Hidden Yarn', 2, 1170, 2340]);
$ins->execute([$oid, $visId, null, 'Visible Yarn extra', 1, 1300, 1300]);

$ref = new ReflectionMethod(AdminController::class, 'createBundleFromOrder');
$ref->setAccessible(true);
$created = $ref->invoke(null, $oid);
if (empty($created['ok']) || ($created['title'] ?? '') !== 'New bundle' || (int)($created['items'] ?? 0) !== 2) {
    fail('Create Bundle should snapshot unique order products, got ' . json_encode($created));
}
$bid = (int)$created['bundle_id'];
$bundle = $pdo->query('SELECT * FROM bundles WHERE id = ' . $bid)->fetch();
if (!$bundle || $bundle['title'] !== 'New bundle' || (int)$bundle['is_public'] !== 0) {
    fail('new bundle must be titled New bundle and hidden, got ' . json_encode($bundle));
}
$members = $pdo->query('SELECT product_id, min_qty FROM bundle_items WHERE bundle_id = ' . $bid . ' ORDER BY sort_order')->fetchAll();
if (count($members) !== 2) {
    fail('bundle should contain 2 unique products, got ' . count($members));
}
$byPid = [];
foreach ($members as $m) {
    $byPid[(int)$m['product_id']] = (int)$m['min_qty'];
}
if (($byPid[$visId] ?? 0) !== 4 || ($byPid[$hidId] ?? 0) !== 2) {
    fail('bundle mins should sum order qtys (4 and 2), got ' . json_encode($byPid));
}
if (Catalog::findBundle($bid, false) || Catalog::bundles(false)) {
    fail('hidden New bundle leaked onto the storefront');
}
pass('Create Bundle makes a hidden New bundle from the order lines');

/* ----- Shopify API sync overwrites Retail Status, keeps Wholesale Status ----- */
$pdo->prepare('UPDATE products SET shopify_product_id = ?, is_public = 1, status = ? WHERE id = ?')
    ->execute(['99', 'active', $visId]);
$synced = ShopifyService::upsertProducts([[
    'shopify_product_id' => '99',
    'sku' => 'VIS',
    'title' => 'Visible Yarn from Shopify',
    'description' => 'synced',
    'category' => 'Fingering',
    'price_cents' => 2500,
    'stock' => 11,
    'status' => 'inactive',
    'image_url' => '',
    'images_json' => '',
]]);
if ((int)($synced['updated'] ?? 0) < 1) {
    fail('Shopify upsert should update the matched product, got ' . json_encode($synced));
}
$after = $pdo->query('SELECT title, status, is_public, stock FROM products WHERE id = ' . $visId)->fetch();
if ($after['status'] !== 'inactive') {
    fail('full Shopify sync should overwrite Retail Status, got ' . json_encode($after));
}
if ((int)$after['is_public'] !== 1) {
    fail('Shopify sync must not change Wholesale Status');
}
if ((int)$after['stock'] !== 11 || $after['title'] !== 'Visible Yarn from Shopify') {
    fail('Shopify sync should refresh catalog fields, got ' . json_encode($after));
}
pass('Shopify sync overwrites Retail Status and preserves Wholesale Status');

/* ----- CSV import same contract ----- */
$pdo->prepare('UPDATE products SET is_public = 0, status = ? WHERE id = ?')->execute(['active', $hidId]);
$fh = fopen('php://temp', 'r+');
$headers = ['Handle', 'Title', 'Body (HTML)', 'Type', 'Published', 'Variant SKU', 'Variant Inventory Qty', 'Variant Price', 'Status'];
fputcsv($fh, $headers);
fputcsv($fh, ['hidden-yarn', 'Hidden Yarn CSV', '<p>x</p>', 'Fingering', 'true', 'HID', '8', '22.00', 'draft']);
rewind($fh);
$csv = stream_get_contents($fh);
fclose($fh);
$imp = ShopifyCsv::import($csv);
if ((int)($imp['updated'] ?? 0) < 1) {
    fail('CSV should update Hidden Yarn by SKU, got ' . json_encode($imp));
}
$csvRow = $pdo->query('SELECT title, status, is_public, stock FROM products WHERE id = ' . $hidId)->fetch();
if ($csvRow['title'] !== 'Hidden Yarn CSV' || $csvRow['status'] !== 'inactive' || (int)$csvRow['stock'] !== 8) {
    fail('CSV should refresh title/stock/Retail Status, got ' . json_encode($csvRow));
}
if ((int)$csvRow['is_public'] !== 0) {
    fail('CSV update must not flip Wholesale Status from hidden to visible');
}
pass('CSV import overwrites Retail Status and preserves Wholesale Status');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "admin cart / create-bundle / RS checks passed\n";
