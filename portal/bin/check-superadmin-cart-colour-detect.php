<?php
declare(strict_types=1);

/**
 * Super Admin storefront cart plus detect-colours-on-import.
 *
 *   php bin/check-superadmin-cart-colour-detect.php
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

$tmp = sys_get_temp_dir() . '/hd-colour-detect-' . getmypid() . '.sqlite';
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
use App\ColourDetect;
use App\Database;
use App\Settings;
use App\ShopifyCsv;
use App\ShopifyService;

$settings = file_get_contents(APP_ROOT . '/app/Views/admin/settings.php');
if (!str_contains($settings, 'Detect colours on import') || !str_contains($settings, 'detect_colours_on_import')) {
    fail('settings should offer Detect colours on import for Shopify and CSV');
}
$prod = file_get_contents(APP_ROOT . '/app/Views/admin/products.php');
if (!str_contains($prod, "key:'colours'") || !str_contains($prod, "key:'variegated'")) {
    fail('products grid should include Colours and Variegated');
}
$cards = file_get_contents(APP_ROOT . '/app/Views/_catalog_cards.php');
if (!str_contains($cards, 'Add to cart') || !str_contains($cards, '$wholesale')) {
    fail('home catalog cards should show Add to cart for shoppers');
}
if (!isset(Catalog::colourSwatches()['gold'])) {
    fail('gold should be a named colour chip');
}
pass('settings, products grid, and gold swatch are wired');

$pdo = Database::pdo();
if (Settings::detectColoursOnImport()) {
    fail('detect colours on import should default off');
}
if ($pdo->query('SELECT id FROM users WHERE id = 0')->fetch() === false) {
    fail('migrate should create the Super Admin sentinel shopper');
}

$_SESSION['user'] = ['id' => 0, 'role' => 'superuser', 'email' => 'root@example.com', 'display_name' => 'Super'];
if (!Auth::canOrder() || Auth::shopperId() !== 0) {
    fail('superuser should be able to shop as user 0');
}
$ctx = Catalog::listingContext();
if (empty($ctx['wholesale'])) {
    fail('home listing should treat the Super Admin as a shopper');
}
pass('Super Admin can see Add to cart, view cart, and check out');

if (!function_exists('imagecreatetruecolor')) {
    fail('GD is required for colour detection');
}

function writeSwatch(string $path, array $blocks): void
{
    $im = imagecreatetruecolor(80, 80);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, 79, 79, $white);
    $n = max(1, count($blocks));
    $bw = (int)floor(56 / $n);
    foreach ($blocks as $i => $rgb) {
        $c = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        $x0 = 12 + $i * $bw;
        imagefilledrectangle($im, $x0, 12, $x0 + $bw - 2, 67, $c);
    }
    imagepng($im, $path);
    imagedestroy($im);
}

$dir = sys_get_temp_dir() . '/hd-swatches-' . getmypid();
@mkdir($dir, 0777, true);
$redPath = $dir . '/red.png';
$rbPath = $dir . '/red-blue.png';
$triPath = $dir . '/tri.png';
writeSwatch($redPath, [[229, 57, 53]]);
writeSwatch($rbPath, [[229, 57, 53], [30, 136, 229]]);
writeSwatch($triPath, [[229, 57, 53], [30, 136, 229], [67, 160, 71]]);

$red = ColourDetect::analyseBytes((string)file_get_contents($redPath));
if (!str_contains($red, 'red') || str_contains($red, 'variegated')) {
    fail('a red swatch should detect red only, got ' . $red);
}
$rb = ColourDetect::analyseBytes((string)file_get_contents($rbPath));
if (!str_contains($rb, 'red') || !str_contains($rb, 'blue')) {
    fail('red+blue should name both colours, got ' . $rb);
}
if (str_contains($rb, 'white')) {
    fail('white background should be ignored, got ' . $rb);
}
$tri = ColourDetect::analyseBytes((string)file_get_contents($triPath));
if (!str_contains($tri, 'variegated')) {
    fail('three distinct colours should flag variegated, got ' . $tri);
}
$parts = array_values(array_filter(explode(',', $tri), static fn($c) => $c !== 'variegated'));
if (count($parts) > 3) {
    fail('detected colours should prefer a maximum of 3 names, got ' . $tri);
}
if (str_contains($tri, 'white')) {
    fail('white background must not be returned as a colour, got ' . $tri);
}
pass('K-means ignores white, names simple colours, and flags variegated');

$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, colours, image_url) VALUES (?,?,?,?,1,'active',?,?)")
    ->execute(['C1', 'Hand edited', 2000, 4, 'coral,red', $redPath]);
$pid = (int)$pdo->lastInsertId();

$csv = "Handle,Title,Variant SKU,Variant Price,Variant Inventory Qty,Published,Status,Image Src\n" .
    "hand-edited,Hand edited,C1,20.00,4,true,active," . $redPath . "\n";
$imp = ShopifyCsv::import($csv);
if (!($imp['ok'] ?? false) || (int)($imp['updated'] ?? 0) < 1) {
    fail('CSV should update the existing SKU, got ' . json_encode($imp));
}
$kept = (string)$pdo->query('SELECT colours FROM products WHERE id = ' . $pid)->fetchColumn();
if ($kept !== 'coral,red') {
    fail('sync must not overwrite admin colours while detect is off, got ' . $kept);
}

Settings::set('detect_colours_on_import', '1');
if (!Settings::detectColoursOnImport()) {
    fail('detect colours setting should turn on');
}
$imp2 = ShopifyCsv::import($csv);
$after = Catalog::coloursCsv((string)$pdo->query('SELECT colours FROM products WHERE id = ' . $pid)->fetchColumn());
if (!str_contains($after, 'red')) {
    fail('detect-on-import should rewrite colours from the feature image, got ' . $after);
}
pass('admin colours survive sync until Detect colours on import is on');

$pdo->prepare("INSERT INTO products (sku, title, price_cents, stock, is_public, status, colours) VALUES (?,?,?,?,1,'active',?)")
    ->execute(['ED', 'Editable', 1000, 2, 'blue']);
$eid = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE products SET colours = ? WHERE id = ?')->execute(['blue,green,variegated', $eid]);
$row = $pdo->query('SELECT colours FROM products WHERE id = ' . $eid)->fetch();
if (!Catalog::productHasColour($row, 'variegated') || !Catalog::productHasColour($row, 'green')) {
    fail('admin-editable colours + variegated should persist');
}
pass('products colours column and variegated flag persist');

@unlink($redPath);
@unlink($rbPath);
@unlink($triPath);
@rmdir($dir);
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

echo "superadmin cart / colour-detect checks passed\n";
