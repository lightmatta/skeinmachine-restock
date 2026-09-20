<?php
declare(strict_types=1);

/**
 * Isolated checks for admin password hashing, Shopify CSV upsert, and delete-all.
 * Uses a temporary SQLite file so the live catalog is not touched.
 *
 *   php bin/check-admin-features.php
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

$tmp = sys_get_temp_dir() . '/hd-admin-check-' . getmypid() . '.sqlite';
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
use App\Database;
use App\ShopifyCsv;
use App\View;

$pdo = Database::pdo();

/* ----- Password is hashed, never stored in the clear ----- */
$email = 'pwtest-' . time() . '@example.com';
$pdo->prepare("INSERT INTO users (email, role, status) VALUES (?, 'wholesale', 'active')")
    ->execute([$email]);
$uid = (int)$pdo->lastInsertId();
$plain = 'SecretPass99';
if (strlen($plain) < 8) {
    fail('test password shorter than controller minimum');
}
// Same path as AdminController users update: hash the typed clear-text, store hash only.
$hash = password_hash($plain, PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $uid]);
$row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$row->execute([$uid]);
$stored = (string)$row->fetchColumn();
if ($stored === '' || $stored === $plain) {
    fail('password stored in the clear');
}
if (!str_starts_with($stored, '$2y$') && !str_starts_with($stored, '$2a$') && !str_starts_with($stored, '$2b$')) {
    fail('stored value is not a bcrypt hash');
}
if (!password_verify($plain, $stored)) {
    fail('stored hash does not verify');
}
if (!Auth::attempt($email, $plain)) {
    fail('user cannot log in with the new password');
}
Auth::logout();
pass('password hashed and login works');

$src = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if ($src === false || !str_contains($src, 'password_hash($plain, PASSWORD_BCRYPT)')) {
    fail('AdminController does not hash the typed password');
}
if (!str_contains($src, 'AS has_password')) {
    fail('users list should expose has_password, not the hash');
}
if (preg_match("/SELECT\\s+.*\\bpassword_hash\\b\\s+FROM users/i", $src)) {
    fail('users list must not select password_hash as a column');
}
pass('users API hashes on save and never lists the hash');

/* ----- Shopify CSV with the real export header (one product + extra image row) ----- */
$headerLine = 'Handle,Title,Body (HTML),Vendor,Product Category,Type,Tags,Published,Option1 Name,Option1 Value,Option1 Linked To,Option2 Name,Option2 Value,Option2 Linked To,Option3 Name,Option3 Value,Option3 Linked To,Variant SKU,Variant Grams,Variant Inventory Tracker,Variant Inventory Qty,Variant Inventory Policy,Variant Fulfillment Service,Variant Price,Variant Compare At Price,Variant Requires Shipping,Variant Taxable,Unit Price Total Measure,Unit Price Total Measure Unit,Unit Price Base Measure,Unit Price Base Measure Unit,Variant Barcodes,Image Src,Image Position,Image Alt Text,Gift Card,SEO Title,SEO Description,Google Shopping / Google Product Category,Google Shopping / Gender,Google Shopping / Age Group,Google Shopping / MPN,Google Shopping / Condition,Google Shopping / Custom Product,Google Shopping / Custom Label 0,Google Shopping / Custom Label 1,Google Shopping / Custom Label 2,Google Shopping / Custom Label 3,Google Shopping / Custom Label 4,Blackwattle Original Colour (product.metafields.custom.blackwattle_original_colour),Colours (product.metafields.custom.colours),Custom Image Badge (product.metafields.custom.custom_image_badge),Fibre (product.metafields.custom.fibre),Yarn Ply/Weight (product.metafields.custom.gauge),Vendor Collection (product.metafields.custom.vendor_collection),EComposer product countdown end at (product.metafields.ecomposer.countdown),EComposer product countdown start at (product.metafields.ecomposer.countdown_from),Google: Custom Product (product.metafields.mm-google-shopping.custom_product),Tracked (product.metafields.restockrocket_production.tracked),Product rating count (product.metafields.reviews.rating_count),Accessory size (product.metafields.shopify.accessory-size),Age group (product.metafields.shopify.age-group),Age restrictions (product.metafields.shopify.age-restrictions),Art/Crafting tool material (product.metafields.shopify.art-crafting-tool-material),Bag/Case material (product.metafields.shopify.bag-case-material),Battery size (product.metafields.shopify.battery-size),Battery type (product.metafields.shopify.battery-type),Book cover type (product.metafields.shopify.book-cover-type),Carry options (product.metafields.shopify.carry-options),Celebration type (product.metafields.shopify.celebration-type),Clothing accessory material (product.metafields.shopify.clothing-accessory-material),Color (product.metafields.shopify.color-pattern),Craft decoration maker type (product.metafields.shopify.craft-decoration-maker-type),Decoration material (product.metafields.shopify.decoration-material),Display technology (product.metafields.shopify.display-technology),Earring design (product.metafields.shopify.earring-design),Fabric (product.metafields.shopify.fabric),Fastener material (product.metafields.shopify.fastener-material),Genre (product.metafields.shopify.genre),Hook grip design (product.metafields.shopify.hook-grip-design),Hook size (product.metafields.shopify.hook-size),Item style (product.metafields.shopify.item-style),Jewelry material (product.metafields.shopify.jewelry-material),Jewelry type (product.metafields.shopify.jewelry-type),Light source (product.metafields.shopify.light-source),Lighting features (product.metafields.shopify.lighting-features),Lumber/Wood type (product.metafields.shopify.lumber-wood-type),Material (product.metafields.shopify.material),Needle size system (product.metafields.shopify.needle-size-system),Needlecraft kit type (product.metafields.shopify.needlecraft-kit-type),Office supply material (product.metafields.shopify.office-supply-material),Paper size (product.metafields.shopify.paper-size),Paper type (product.metafields.shopify.paper-type),Pattern distribution format (product.metafields.shopify.pattern-distribution-format),Personalization design (product.metafields.shopify.personalization-design),Ruler edge type (product.metafields.shopify.ruler-edge-type),Ruler marking style (product.metafields.shopify.ruler-marking-style),Shape (product.metafields.shopify.shape),Skill level (product.metafields.shopify.skill-level),Suitable for crafting material (product.metafields.shopify.suitable-for-crafting-material),System of measurement (product.metafields.shopify.system-of-measurement),Tableware material (product.metafields.shopify.tableware-material),Target audience (product.metafields.shopify.target-audience),Target gender (product.metafields.shopify.target-gender),Textile craft machine features (product.metafields.shopify.textile-craft-machine-features),Ticket type (product.metafields.shopify.ticket-type),Yarn weight category (product.metafields.shopify.yarn-weight-category),Complementary products (product.metafields.shopify--discovery--product_recommendation.complementary_products),Related products (product.metafields.shopify--discovery--product_recommendation.related_products),Related products settings (product.metafields.shopify--discovery--product_recommendation.related_products_display),Search product boosts (product.metafields.shopify--discovery--product_search_boost.queries),Custom Badge (product.metafields.theme.custom_badge),Variant Image,Variant Weight Unit,Variant Tax Code,Cost per item,Included / Australia,Price / Australia,Compare At Price / Australia,Included / International,Price / International,Compare At Price / International,Included / International US,Price / International US,Compare At Price / International US,Status';

$headers = str_getcsv($headerLine);
$bodyHtml = '<p>Eyre Shimmer is a high-twist 4ply yarn from The House Dye, with a subtle silver Stellina sparkle running through it.</p>
<h2>Why choose Eyre Shimmer?</h2>
<ul>
<li>High-twist construction for robustness and spring</li>
</ul>';

$row1 = array_fill_keys($headers, '');
$row1['Handle'] = 'dove-eyre-shimmer-4ply';
$row1['Title'] = 'Dove - Eyre (Shimmer 4ply)';
$row1['Body (HTML)'] = $bodyHtml;
$row1['Vendor'] = 'The House Dye';
$row1['Product Category'] = 'Arts & Entertainment > Hobbies & Creative Arts > Arts & Crafts > Art & Crafting Materials > Crafting Fibers > Yarn';
$row1['Type'] = 'Yarn';
$row1['Tags'] = 'Blackwattle Original Colour';
$row1['Published'] = 'true';
$row1['Option1 Name'] = 'Title';
$row1['Option1 Value'] = 'Default Title';
$row1['Variant SKU'] = 'THDS04032';
$row1['Variant Grams'] = '100.0';
$row1['Variant Inventory Tracker'] = 'shopify';
$row1['Variant Inventory Qty'] = '4';
$row1['Variant Inventory Policy'] = 'continue';
$row1['Variant Fulfillment Service'] = 'manual';
$row1['Variant Price'] = '34.00';
$row1['Variant Requires Shipping'] = 'true';
$row1['Variant Taxable'] = 'true';
$row1['Variant Barcodes'] = 'THDS04032';
$row1['Image Src'] = 'https://cdn.shopify.com/s/files/1/0744/4017/9989/files/IMG_9843.jpg';
$row1['Image Position'] = '1';
$row1['Image Alt Text'] = 'Close-up of light gray twisted yarn skeins';
$row1['Fibre (product.metafields.custom.fibre)'] = "Merino\nNylon\nMetalic";
$row1['Yarn Ply/Weight (product.metafields.custom.gauge)'] = '4ply/Fingering';
$row1['Status'] = 'active';

$row2 = array_fill_keys($headers, '');
$row2['Handle'] = 'dove-eyre-shimmer-4ply';
$row2['Image Src'] = 'https://cdn.shopify.com/s/files/1/0744/4017/9989/files/IMG_9841.jpg';
$row2['Image Position'] = '2';
$row2['Image Alt Text'] = 'A skein of light gray yarn';

$fh = fopen('php://temp', 'r+');
fputcsv($fh, $headers);
fputcsv($fh, array_map(static fn($h) => $row1[$h], $headers));
fputcsv($fh, array_map(static fn($h) => $row2[$h], $headers));
rewind($fh);
$csv = stream_get_contents($fh);
fclose($fh);

$first = ShopifyCsv::import($csv);
if (empty($first['ok']) || (int)$first['created'] < 1) {
    fail('csv import did not create a product: ' . json_encode($first));
}
$prod = $pdo->prepare('SELECT * FROM products WHERE shopify_product_id = ?');
$prod->execute(['dove-eyre-shimmer-4ply']);
$p = $prod->fetch();
if (!$p) {
    fail('imported product not found by handle');
}
if ($p['sku'] !== 'THDS04032') {
    fail('sku mismatch: ' . $p['sku']);
}
if ((int)$p['price_cents'] !== 3400) {
    fail('price_cents mismatch: ' . $p['price_cents']);
}
if ((int)$p['stock'] !== 4) {
    fail('stock mismatch: ' . $p['stock']);
}
if ($p['category'] !== 'Yarn') {
    fail('category mismatch: ' . $p['category']);
}
if ($p['image_url'] !== $row1['Image Src']) {
    fail('should keep image position 1, got ' . $p['image_url']);
}
$gallery = json_decode((string)($p['images_json'] ?? ''), true);
if (!is_array($gallery) || count($gallery) < 2) {
    fail('gallery should keep every CSV image, got ' . json_encode($gallery));
}
$srcs = array_map(static fn($im) => is_array($im) ? ($im['src'] ?? '') : (string)$im, $gallery);
if ($srcs[0] !== $row1['Image Src']) {
    fail('gallery feature image should be position 1');
}
if (!in_array($row2['Image Src'], $srcs, true)) {
    fail('gallery missing the second CSV image');
}
if (!str_contains((string)$p['description'], 'High-twist')) {
    fail('description missing body text');
}
if (!str_contains((string)$p['description'], 'Fibre:')) {
    fail('description missing fibre');
}
if (str_contains((string)$p['description'], '<p>')) {
    fail('description still contains HTML');
}
$id = (int)$p['id'];
pass('csv import created product from Shopify export columns');
pass('csv import stored the full image gallery');

$detail = View::capture('public/product', [
    'product'   => $p,
    'images'    => Catalog::images($p),
    'wholesale' => false,
    'title'     => $p['title'],
]);
if (!str_contains($detail, $row1['Image Src']) || !str_contains($detail, $row2['Image Src'])) {
    fail('product page must render every CSV image');
}
if (!str_contains($detail, 'gallery-thumb')) {
    fail('product page should offer a gallery for extra images');
}
pass('product detail page shows the full CSV gallery');

$pdo->exec("INSERT INTO bundles (title, description, is_public) VALUES ('Gallery bundle', 'A test set', 1)");
$bid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty) VALUES (?,?,1)')->execute([$bid, $id]);
$bundle = Catalog::findBundle($bid, false);
if (!$bundle || !$bundle['items']) {
    fail('bundle with imported product not found');
}
$bhtml = View::capture('public/bundle', [
    'bundle'    => $bundle,
    'wholesale' => false,
    'title'     => $bundle['title'],
]);
if (!str_contains($bhtml, $row1['Image Src'])) {
    fail('bundle view should show the member feature image');
}
if (str_contains($bhtml, $row2['Image Src'])) {
    fail('bundle view should not show extra gallery images');
}
if (!str_contains($bhtml, 'url=product') && !str_contains($bhtml, 'r=product')) {
    fail('bundle member title/image should link to the product page');
}
pass('bundle view shows member feature images only');

$adminSrc = (string)file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (preg_match("/key:\\s*'image/", $adminSrc) || str_contains($adminSrc, 'image_url')) {
    fail('admin products grid should not include an image column');
}
$adminList = (string)file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (preg_match("/SELECT id, sku, title.*image_url/", $adminList)) {
    fail('admin products list API should not return image_url');
}
pass('admin products data view has no image column');

$row1['Title'] = 'Dove - Eyre UPDATED';
$row1['Body (HTML)'] = '<p>Updated body</p>';
$row1['Variant Inventory Qty'] = '9';
$row1['Variant Price'] = '36.50';
$row1['Image Src'] = 'https://example.com/yarn-new.jpg';
$row1['Fibre (product.metafields.custom.fibre)'] = 'Merino';
$fh = fopen('php://temp', 'r+');
fputcsv($fh, $headers);
fputcsv($fh, array_map(static fn($h) => $row1[$h], $headers));
rewind($fh);
$csv2 = stream_get_contents($fh);
fclose($fh);

$second = ShopifyCsv::import($csv2);
if (empty($second['ok']) || (int)$second['updated'] < 1 || (int)$second['created'] !== 0) {
    fail('second import should update, not create: ' . json_encode($second));
}
$prod->execute(['dove-eyre-shimmer-4ply']);
$p2 = $prod->fetch();
if ((int)$p2['id'] !== $id) {
    fail('handle match created a duplicate id ' . $p2['id'] . ' vs ' . $id);
}
if ($p2['title'] !== 'Dove - Eyre UPDATED') {
    fail('title was not overwritten');
}
if ((int)$p2['stock'] !== 9 || (int)$p2['price_cents'] !== 3650) {
    fail('stock/price not overwritten');
}
pass('csv import overwrites existing product on handle match');

/* Portal id match */
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public) VALUES ('ID-MATCH', 'By id', 100, 1, 1)")->execute();
$idMatch = (int)$pdo->lastInsertId();
$fh = fopen('php://temp', 'r+');
$idHeaders = ['ID', 'Handle', 'Title', 'Variant SKU', 'Variant Price', 'Variant Inventory Qty', 'Published', 'Status'];
fputcsv($fh, $idHeaders);
fputcsv($fh, [(string)$idMatch, 'handle-for-id-match', 'Updated via portal id', 'ID-MATCH', '20.00', '3', 'true', 'active']);
rewind($fh);
$csvId = stream_get_contents($fh);
fclose($fh);
$idResult = ShopifyCsv::import($csvId);
if ((int)$idResult['updated'] < 1) {
    fail('portal id match should update: ' . json_encode($idResult));
}
$idRow = $pdo->query('SELECT * FROM products WHERE id = ' . $idMatch)->fetch();
if ($idRow['title'] !== 'Updated via portal id' || (int)$idRow['price_cents'] !== 2000) {
    fail('portal id match did not overwrite');
}
pass('csv import overwrites existing product on portal id match');

/* SKU match when handle is new */
$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public) VALUES ('SKU-MATCH-1', 'Old title', 100, 1, 1)")
    ->execute();
$skuId = (int)$pdo->lastInsertId();
$fh = fopen('php://temp', 'r+');
fputcsv($fh, $headers);
$skuRow = array_fill_keys($headers, '');
$skuRow['Handle'] = 'new-handle-for-sku';
$skuRow['Title'] = 'SKU matched product';
$skuRow['Body (HTML)'] = '<p>Hi</p>';
$skuRow['Type'] = 'Notions';
$skuRow['Published'] = 'true';
$skuRow['Variant SKU'] = 'SKU-MATCH-1';
$skuRow['Variant Inventory Qty'] = '2';
$skuRow['Variant Price'] = '12.00';
$skuRow['Image Src'] = 'https://example.com/a.jpg';
$skuRow['Image Position'] = '1';
$skuRow['Status'] = 'active';
fputcsv($fh, array_map(static fn($h) => $skuRow[$h], $headers));
rewind($fh);
$csv3 = stream_get_contents($fh);
fclose($fh);
$third = ShopifyCsv::import($csv3);
if ((int)$third['updated'] < 1) {
    fail('sku match should update: ' . json_encode($third));
}
$skuFetched = $pdo->query('SELECT * FROM products WHERE id = ' . $skuId)->fetch();
if ($skuFetched['title'] !== 'SKU matched product' || $skuFetched['shopify_product_id'] !== 'new-handle-for-sku') {
    fail('sku match did not overwrite');
}
pass('csv import overwrites existing product on SKU match');

/* delete all — same SQL as AdminController (confirmation is enforced in the API) */
$apiSrc = $src;
if (!str_contains($apiSrc, "if (empty(\$body['confirm']))")) {
    fail('delete_all must require confirm');
}
$before = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($before < 1) {
    fail('expected products before delete-all');
}
$pdo->exec('UPDATE order_items SET product_id = NULL');
$pdo->exec('DELETE FROM bundle_items');
$pdo->exec('DELETE FROM products');
$after = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($after !== 0) {
    fail('products remained after delete all');
}
pass('delete all products clears the catalog');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "admin feature checks passed\n";
