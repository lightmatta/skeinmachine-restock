<?php
declare(strict_types=1);

/**
 * Bundle item drag-reorder, wholesale mosaic previews, truncated bundle
 * descriptions, and the persistent add-to-bundle keyword filter.
 *
 *   php bin/check-bundle-reorder-mosaic.php
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

$tmp = sys_get_temp_dir() . '/hd-brm-' . getmypid() . '.sqlite';
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
use App\View;

$pdo = Database::pdo();

$cols = array_column($pdo->query('PRAGMA table_info(bundle_items)')->fetchAll(), 'name');
if (!in_array('sort_order', $cols, true)) {
    fail('bundle_items.sort_order column missing after migrate');
}
pass('bundle_items.sort_order exists');

$images = [
    'https://cdn.shopify.com/s/files/1/x/coral.jpg?v=1',
    'https://cdn.shopify.com/s/files/1/x/reef.jpg?v=1',
    'https://cdn.shopify.com/s/files/1/x/forest.jpg?v=1',
    'https://cdn.shopify.com/s/files/1/x/pearl.jpg?v=1',
];
$ins = $pdo->prepare("INSERT INTO products (sku, title, description, image_url, price_cents, stock, is_public, status)
                      VALUES (?,?,?,?,2400,12,1,'active')");
$pids = [];
foreach (['Coral', 'Reef', 'Forest', 'Pearl'] as $i => $name) {
    $ins->execute(['SKU-' . ($i + 1), $name, 'Colourway ' . $name, $images[$i]]);
    $pids[] = (int)$pdo->lastInsertId();
}

$longHtml = '<p>A curated starter set for new stockists with extra words so the listing will truncate past twenty words on the card and still keep a more link.</p>';
$pdo->prepare("INSERT INTO bundles (title, description, is_public) VALUES ('House Starter Kit', ?, 1)")->execute([$longHtml]);
$bid = (int)$pdo->lastInsertId();
$add = $pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty, sort_order) VALUES (?,?,1,?)');
foreach ($pids as $i => $pid) {
    $add->execute([$bid, $pid, $i]);
}

/* ----- reorder ----- */
$items = Catalog::bundleItems($bid, true);
$titles = array_column($items, 'title');
if ($titles !== ['Coral', 'Reef', 'Forest', 'Pearl']) {
    fail('initial order should follow sort_order, got ' . json_encode($titles));
}
$ids = array_map(static fn($it) => (int)$it['bundle_item_id'], $items);
$reversed = array_reverse($ids);
if (!Catalog::reorderBundleItems($bid, $reversed)) {
    fail('reorderBundleItems should accept the full id list');
}
$after = array_column(Catalog::bundleItems($bid, true), 'title');
if ($after !== ['Pearl', 'Forest', 'Reef', 'Coral']) {
    fail('catalog should follow the saved drag order, got ' . json_encode($after));
}
if (Catalog::reorderBundleItems($bid, [$ids[0]])) {
    fail('partial id lists must be rejected');
}
pass('bundle item order saves and is used on the catalog');

$adminSrc = (string)file_get_contents(APP_ROOT . '/app/Views/admin/bundles.php');
$ctrlSrc = (string)file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, 'draggable="true"') || !str_contains($adminSrc, 'op:\'reorder\'') || !str_contains($adminSrc, 'drag-grip')) {
    fail('admin bundles page must drag-and-drop rows and POST reorder live');
}
if (!str_contains($ctrlSrc, "op === 'reorder'") || !str_contains($ctrlSrc, 'reorderBundleItems')) {
    fail('bundle_items API must expose a reorder op');
}
pass('admin UI posts reorder after every drop');

/* ----- keyword filter persists ----- */
if (!str_contains($adminSrc, 'add-product-filter') || !str_contains($adminSrc, 'Type a keyword to filter')) {
    fail('add-to-bundle dropdown needs a keyword filter input');
}
if (!preg_match("/var addItem = card\.querySelector\('\.add-item'\);.*?var tog =/s", $adminSrc, $addMatch)) {
    fail('missing add-item click handler');
}
if (str_contains($addMatch[0], 'location.reload')) {
    fail('adding a product must not reload the page (that would clear the keyword)');
}
if (!str_contains($addMatch[0], 'applyFilter(card)')) {
    fail('after adding a product the current keyword filter should stay applied');
}
$ui = View::capture('admin/bundles', [
    'active' => 'bundles',
    'readonly' => false,
    'bundles' => $pdo->query('SELECT * FROM bundles')->fetchAll(),
    'byBundle' => [$bid => [
        ['id' => 1, 'title' => 'Coral', 'price_cents' => 2400, 'min_qty' => 1],
        ['id' => 2, 'title' => 'Reef', 'price_cents' => 2400, 'min_qty' => 1],
    ]],
    'products' => [
        ['id' => $pids[0], 'title' => 'Coral', 'price_cents' => 2400],
        ['id' => $pids[1], 'title' => 'Reef', 'price_cents' => 2400],
        ['id' => $pids[2], 'title' => 'Forest', 'price_cents' => 2400],
    ],
]);
if (!str_contains($ui, 'add-product-filter') || !str_contains($ui, 'draggable="true"')) {
    fail('rendered admin bundles missing filter or drag handles');
}
$staffUi = View::capture('admin/bundles', [
    'active' => 'bundles',
    'readonly' => true,
    'bundles' => $pdo->query('SELECT * FROM bundles')->fetchAll(),
    'byBundle' => [$bid => []],
    'products' => [],
]);
if (str_contains($staffUi, 'add-product-filter') || str_contains($staffUi, 'draggable="true"')) {
    fail('staff should not get drag-reorder or the add-product filter');
}
pass('add-to-bundle keyword filter stays until the admin clears it');

/* ----- mosaic + ...more on wholesale home ----- */
if (Catalog::mosaicCols(1) !== 1 || Catalog::mosaicCols(2) !== 2 || Catalog::mosaicCols(4) !== 2 || Catalog::mosaicCols(5) !== 3 || Catalog::mosaicCols(9) !== 3) {
    fail('mosaicCols should pack thumbs into a square-ish grid');
}

$found = Catalog::findBundle($bid, true);
if (!$found || count($found['items']) !== 4) {
    fail('wholesale bundle should include all four colourways');
}
$preview = catalog_bundle_preview($found);
if (!str_contains($preview, 'class="thumb has-image mosaic"') || !str_contains($preview, '--mosaic-cols:2')) {
    fail('multi-product bundle preview should be a 2x2 mosaic, got ' . $preview);
}
if (substr_count($preview, 'width="100"') !== 4 || substr_count($preview, 'width=100') !== 4) {
    fail('each colour thumb must request width=100, got ' . $preview);
}
foreach ($images as $url) {
    $sized = Catalog::sizedUrl($url, 100);
    if (!str_contains($preview, $sized) && !str_contains($preview, 'width=100')) {
        fail('mosaic missing sized colour URL ' . $sized);
    }
}

$htmlDesc = catalog_description($longHtml);
if (!str_contains($htmlDesc, 'catalog-more') || !str_contains($htmlDesc, '...more') || str_contains($htmlDesc, '<p>')) {
    fail('long bundle descriptions should use the same ...more toggle as products: ' . $htmlDesc);
}
if (!preg_match('/catalog-desc-body">([^<]+)</', $htmlDesc, $m)) {
    fail('truncated bundle description missing body');
}
if (str_contains($m[1], ' keep ') || str_contains($m[1], ' card ')) {
    fail('collapsed bundle description leaked words after the 20-word limit: ' . $m[1]);
}

$cards = View::capture('_catalog_cards', [
    'products'  => [],
    'bundles'   => [$found],
    'wholesale' => true,
    'showStock' => false,
    'ignoreMin' => false,
]);
if (!str_contains($cards, 'thumb has-image mosaic') || !str_contains($cards, 'width="100"')) {
    fail('wholesale home should render the colour mosaic');
}
if (!str_contains($cards, '...more') || !str_contains($cards, 'catalog-more')) {
    fail('wholesale home should shorten bundle descriptions with ...more');
}
if (str_contains($cards, 'gap:') && preg_match('/thumb mosaic[^>]*>/', $cards, $mm) && str_contains($mm[0], 'gap:')) {
    fail('mosaic markup should not set a gap');
}

$css = (string)file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($css, '.product .thumb.mosaic') || !str_contains($css, 'gap: 0') || !str_contains($css, 'aspect-ratio: 1 / 1')) {
    fail('mosaic CSS must be gapless and square');
}

$single = $found;
$single['items'] = array_slice($found['items'], 0, 1);
$singleThumb = catalog_bundle_preview($single);
if (str_contains($singleThumb, 'mosaic')) {
    fail('a one-product bundle should keep a single feature thumb');
}
pass('wholesale home shortens bundle copy and tiles every colour at width=100');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
