<?php
declare(strict_types=1);

/**
 * Isolated checks for Shopify client-credentials Admin API auth (2026).
 *
 *   php bin/check-shopify-oauth.php
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

$tmp = sys_get_temp_dir() . '/hd-shopify-oauth-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\Settings;
use App\ShopifyService;
use App\View;

$calls = [];
$tokenHits = 0;
$shopHits = 0;
$productHits = 0;
$force401once = false;

ShopifyService::$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$tokenHits, &$shopHits, &$productHits, &$force401once): array {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if (str_contains($url, '/admin/oauth/access_token')) {
        $tokenHits++;
        parse_str((string)$body, $params);
        if (($params['grant_type'] ?? '') !== 'client_credentials') {
            return [400, json_encode(['error' => 'unsupported_grant_type'])];
        }
        if (($params['client_id'] ?? '') !== 'cid-123' || ($params['client_secret'] ?? '') !== 'csec-456') {
            return [401, json_encode(['error' => 'invalid_client'])];
        }
        return [200, json_encode([
            'access_token' => 'tok_' . $tokenHits,
            'scope'        => 'read_products',
            'expires_in'   => 86399,
        ])];
    }
    $auth = '';
    foreach ($headers as $h) {
        if (stripos($h, 'X-Shopify-Access-Token:') === 0) {
            $auth = trim(substr($h, strlen('X-Shopify-Access-Token:')));
        }
    }
    if (str_contains($url, 'shop.json')) {
        $shopHits++;
        if ($auth === '') {
            return [401, json_encode(['errors' => 'Invalid API key or access token'])];
        }
        return [200, json_encode(['shop' => ['name' => 'HouseDye Yarns']])];
    }
    if (str_contains($url, 'products.json')) {
        $productHits++;
        if ($force401once) {
            $force401once = false;
            return [401, json_encode(['errors' => 'Invalid API key or access token'])];
        }
        return [200, json_encode(['products' => [[
            'id' => 99,
            'title' => 'Coral Reef',
            'body_html' => '<p>Sock yarn</p>',
            'product_type' => 'Fingering',
            'image' => ['src' => 'https://cdn.shopify.com/s/files/x.jpg'],
            'images' => [['src' => 'https://cdn.shopify.com/s/files/x.jpg', 'alt' => 'coral']],
            'variants' => [['price' => '24.00', 'sku' => 'Y1', 'inventory_quantity' => 4]],
        ]]])];
    }
    return [404, '{}'];
};

/* ----- settings UI ----- */
$setUi = View::capture('admin/settings', ['active' => 'settings', 'settings' => Settings::all(), 'saved' => false]);
if (str_contains($setUi, 'name="shopify_token"') || str_contains($setUi, '<span>Admin API access token</span>')) {
    fail('settings should not ask for a copyable Admin API access token');
}
if (!str_contains($setUi, 'Client ID (API key)') || !str_contains($setUi, 'name="shopify_client_id"')) {
    fail('settings should include Client ID (API key)');
}
if (!str_contains($setUi, 'Client secret (API secret)') || !str_contains($setUi, 'name="shopify_client_secret"')) {
    fail('settings should include Client secret (API secret)');
}
if (!str_contains($setUi, 'client-credentials')) {
    fail('settings should explain the client-credentials grant');
}
pass('Settings collect Client ID and Client secret instead of an Admin API token');

/* ----- not configured without credentials ----- */
if (ShopifyService::configured()) {
    fail('empty credentials should not count as configured');
}
$r = ShopifyService::testConnection();
if (($r['ok'] ?? false) || !str_contains((string)($r['error'] ?? ''), 'Client ID')) {
    fail('testConnection should refuse until Client ID and secret are set');
}
pass('connection test requires store domain, Client ID, and Client secret');

/* ----- host + token endpoint ----- */
Settings::set('shopify_domain', 'house-dye');
Settings::set('shopify_client_id', 'cid-123');
Settings::set('shopify_client_secret', 'csec-456');
if (ShopifyService::host() !== 'house-dye.myshopify.com') {
    fail('bare shop name should gain .myshopify.com, got ' . ShopifyService::host());
}
$ep = ShopifyService::tokenEndpoint();
if ($ep !== 'https://house-dye.myshopify.com/admin/oauth/access_token') {
    fail('token endpoint mismatch: ' . $ep);
}
$body = ShopifyService::tokenRequestBody();
parse_str($body, $params);
if (($params['grant_type'] ?? '') !== 'client_credentials' || ($params['client_id'] ?? '') !== 'cid-123') {
    fail('token request body should be the client-credentials grant');
}
pass('token endpoint uses client_credentials against the shop domain');

/* ----- obtain token then call Admin API ----- */
$test = ShopifyService::testConnection();
if (empty($test['ok']) || ($test['shop'] ?? '') !== 'HouseDye Yarns') {
    fail('testConnection should succeed with mocked client credentials, got ' . json_encode($test));
}
if ($tokenHits < 1) {
    fail('should POST to /admin/oauth/access_token');
}
$tokenCall = $calls[0];
if ($tokenCall['method'] !== 'POST' || !str_contains($tokenCall['url'], '/admin/oauth/access_token')) {
    fail('first request should be the token grant');
}
$shopCall = null;
foreach ($calls as $c) {
    if (str_contains($c['url'], 'shop.json')) {
        $shopCall = $c;
        break;
    }
}
if (!$shopCall || !in_array('X-Shopify-Access-Token: tok_1', $shopCall['headers'], true)) {
    fail('Admin API calls should send the obtained access token');
}
if (Settings::get('shopify_token') !== 'tok_1') {
    fail('obtained token should be cached');
}
if ((int)Settings::get('shopify_token_expires_at', '0') <= time() + 60) {
    fail('cached token should expire ~24h out');
}
pass('portal obtains an Admin API token and uses it on subsequent requests');

/* ----- cache: no extra token request while valid ----- */
$hitsBefore = $tokenHits;
ShopifyService::testConnection(); // forceRefresh=true on testConnection — it always refreshes
// testConnection always force-refreshes. Check sync/get cache via accessToken() without force.
$hitsAfterTest = $tokenHits;
if ($hitsAfterTest !== $hitsBefore + 1) {
    fail('test connection should request a fresh token each time to prove credentials');
}
$cached = ShopifyService::accessToken();
if ($cached !== 'tok_2' || $tokenHits !== $hitsAfterTest) {
    fail('accessToken should reuse the cached token while it is valid');
}
pass('cached token is reused until shortly before expiry');

/* ----- expiry triggers a new grant ----- */
Settings::set('shopify_token_expires_at', (string)(time() - 10));
$fresh = ShopifyService::accessToken();
if ($fresh !== 'tok_3' || $tokenHits < 3) {
    fail('expired token should be renewed via client_credentials, got ' . $fresh);
}
pass('expired tokens are renewed automatically');

/* ----- 401 on Admin API refreshes the token and retries ----- */
Settings::set('shopify_token', 'tok_stale');
Settings::set('shopify_token_expires_at', (string)(time() + 80000));
$force401once = true;
$sync = ShopifyService::sync();
if (empty($sync['ok']) || ($sync['created'] ?? 0) !== 1) {
    fail('sync should retry after 401 and import products, got ' . json_encode($sync));
}
if ($tokenHits < 4) {
    fail('401 retry should request a new client-credentials token');
}
pass('stale Admin API tokens are refreshed and the product sync retried');

/* ----- changing credentials forgets the cache ----- */
Settings::set('shopify_token', 'keep-me');
Settings::set('shopify_token_expires_at', '9999999999');
ShopifyService::forgetCachedToken();
if (Settings::get('shopify_token') !== '' || Settings::get('shopify_token_expires_at') !== '0') {
    fail('forgetCachedToken should clear the stored Admin API token');
}
$adminSrc = file_get_contents(APP_ROOT . '/app/Controllers/AdminController.php');
if (!str_contains($adminSrc, 'shopify_client_id') || !str_contains($adminSrc, 'shopify_client_secret') || !str_contains($adminSrc, 'forgetCachedToken')) {
    fail('settings save should persist Client ID/secret and drop a stale cached token');
}
if (str_contains($adminSrc, "Settings::set('shopify_token', trim")) {
    fail('admins should not paste a permanent Admin API access token anymore');
}
pass('saving new Client ID or secret drops any cached Admin API token');

ShopifyService::$transport = null;
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
