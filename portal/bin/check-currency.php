<?php
declare(strict_types=1);

/**
 * Company currency, wholesale display-currency estimates, and mosaic width=100.
 *
 *   php bin/check-currency.php
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

$tmp = sys_get_temp_dir() . '/hd-cur-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Auth;
use App\Currency;
use App\Database;
use App\Settings;
use App\View;

$pdo = Database::pdo();

$cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
if (!in_array('preferred_currency', $cols, true)) {
    fail('users.preferred_currency column missing after migrate');
}
if (Settings::currency() !== 'AUD') {
    fail('company currency should default to AUD, got ' . Settings::currency());
}
if (!str_ends_with(money(123456), ' AUD') || !str_contains(money(123456), '$1,234.56')) {
    fail('money() should label AUD: ' . money(123456));
}
pass('company currency defaults to AUD and money() labels it');

$usersUi = file_get_contents(APP_ROOT . '/app/Views/admin/users.php');
$settingsUi = file_get_contents(APP_ROOT . '/app/Views/admin/settings.php');
if (str_contains($usersUi, 'Wholesale client views') || str_contains($usersUi, 'wholesaleShowStock')) {
    fail('Users page still has the duplicate Wholesale client views box');
}
if (!str_contains($settingsUi, 'name="currency"') || !str_contains($settingsUi, 'AUD')) {
    fail('Settings should expose a Currency select defaulting to AUD');
}
if (!str_contains($settingsUi, 'wholesale_show_stock')) {
    fail('stock-levels toggle should remain in Settings');
}
pass('Users no longer duplicates wholesale views; Settings has Currency');

$account = file_get_contents(APP_ROOT . '/app/Views/auth/account.php');
if (!str_contains($account, 'preferred_currency') || !str_contains($account, 'Display currency')) {
    fail('wholesale account page should let the client pick a display currency');
}
$ctrl = file_get_contents(APP_ROOT . '/app/Controllers/AuthController.php');
if (!str_contains($ctrl, 'preferred_currency')) {
    fail('account save should persist preferred_currency');
}
pass('wholesale clients can set a display currency on My account');

Currency::primeRates('AUD', ['USD' => 0.65, 'EUR' => 0.60]);
$usd = Currency::convert(10000, 'AUD', 'USD');
if ($usd !== 6500) {
    fail('AUD 100.00 should convert to USD 65.00 at 0.65, got ' . json_encode($usd));
}
if (Currency::rate('AUD', 'AUD') !== 1.0) {
    fail('same-currency rate should be 1');
}
pass('cached exchange rates convert cents');

$pdo->prepare("INSERT INTO users (email, role, status, preferred_currency) VALUES ('shop@example.com','wholesale','active','USD')")->execute();
$uid = (int)$pdo->lastInsertId();
$_SESSION['user'] = ['id' => $uid, 'role' => 'wholesale', 'email' => 'shop@example.com', 'display_name' => 'Wendy'];

if (Currency::viewer() !== 'USD') {
    fail('wholesale viewer currency should be USD, got ' . Currency::viewer());
}
$note = Currency::totalEstimate(10000);
if (!str_contains($note, 'USD') || !str_contains($note, '65.00') || !str_contains($note, 'billed in AUD')) {
    fail('total estimate should show USD convenience and AUD billing: ' . $note);
}
if (!str_contains($note, 'may vary at the time of payment')) {
    fail('estimate should warn that rates may vary at payment');
}
$compact = Currency::compactEstimate(10000);
if (!str_contains($compact, 'fx-compact') || !str_contains($compact, 'USD')) {
    fail('compact estimate missing: ' . $compact);
}
pass('wholesale USD preference shows an AUD-billed FX estimate');

$_SESSION['user'] = ['id' => 0, 'role' => 'admin', 'email' => 'ada@example.com'];
if (Currency::totalEstimate(10000) !== '') {
    fail('admins should not see a client FX estimate');
}
pass('admins see billed currency only');

$print = file_get_contents(APP_ROOT . '/app/Views/wholesale/order_print.php');
$order = file_get_contents(APP_ROOT . '/app/Views/wholesale/order_view.php');
$cart = file_get_contents(APP_ROOT . '/app/Views/wholesale/cart.php');
if (!str_contains($print, 'fx_total_note') || !str_contains($print, 'Total (') || !str_contains($order, 'fx_total_note') || !str_contains($cart, 'fx_total_note')) {
    fail('cart, order view, and print should label the billed currency and offer an FX note');
}
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
$layout = file_get_contents(APP_ROOT . '/app/Views/layouts/app.php');
if (!str_contains($js, '__CURRENCY__') || !str_contains($layout, '__CURRENCY__')) {
    fail('admin grids should format money with the company currency code');
}
pass('order totals and print views carry the billed currency');

$mosaic = file_get_contents(APP_ROOT . '/app/helpers.php');
if (!str_contains($mosaic, 'cdn_image_url($src, 100)') || !str_contains($mosaic, 'width="100"')) {
    fail('bundle mosaic tiles should request width=100');
}
pass('bundle mosaic tiles use width=100');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
