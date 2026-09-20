<?php
declare(strict_types=1);

/**
 * Chat backdrop-minimize and centred header cluster (Home/About/Contact + Apply).
 *
 *   php bin/check-chat-nav-center.php
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

$tmp = sys_get_temp_dir() . '/hd-chat-nav-' . getmypid() . '.sqlite';
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');

$config = require APP_ROOT . '/config/config.php';
$config['db']['path'] = $tmp;
App\Database::connect($config);
App\Database::migrate(APP_ROOT . '/migrations/schema.sql');
App\Auth::boot($config);

use App\View;

/* ----- Apply CTA is a flex child of the centred cluster ----- */
$headerSrc = file_get_contents(APP_ROOT . '/app/Views/layouts/_header.php');
$css = file_get_contents(APP_ROOT . '/public/assets/app.css');
if (!str_contains($headerSrc, 'nav-cluster') || !str_contains($headerSrc, 'nav-cta')) {
    fail('header should keep Home/About/Contact and Apply inside nav-cluster');
}
$clusterPos = strpos($headerSrc, 'class="nav-cluster"');
$ctaPos = strpos($headerSrc, 'nav-cta');
$clusterEnd = strpos($headerSrc, '</div>', $clusterPos);
if ($clusterPos === false || $ctaPos === false || $ctaPos > $clusterEnd) {
    fail('Apply CTA must be inside .nav-cluster so it centres with the menus');
}
if (preg_match('/\.nav-center\s+\.nav-cta\s*\{[^}]*position:\s*absolute/s', $css)
    || str_contains($css, 'left: 100%')) {
    fail('Apply CTA must participate in the centred flex cluster, not sit at left:100%');
}
if (!str_contains($css, 'justify-content: center')) {
    fail('nav cluster should remain centred between brand and login');
}
$header = View::capture('layouts/_header');
if (!str_contains($header, 'Apply for a wholesale account') || !str_contains($header, 'nav-cta')) {
    fail('signed-out header should render the Apply CTA in the cluster');
}
if (!str_contains($header, 'Home') || !str_contains($header, 'About') || !str_contains($header, 'Contact')) {
    fail('signed-out header should render Home, About and Contact');
}
pass('Home/About/Contact and Apply share the centred nav cluster');

/* ----- chat minimizes when the page backdrop is clicked ----- */
$js = file_get_contents(APP_ROOT . '/public/assets/app.js');
if (!str_contains($js, 'function initChat()')) {
    fail('chat init should still live in app.js');
}
if (!preg_match('/document\.addEventListener\(\s*"pointerdown"/', $js)) {
    fail('chat should listen for pointerdown on the document to detect backdrop clicks');
}
if (!str_contains($js, 'panel.contains(t)') || !str_contains($js, 'fab.contains(t)')) {
    fail('backdrop handler should ignore clicks inside the chat window and FAB');
}
if (!preg_match('/if\s*\(\s*!panel\.classList\.contains\(\s*"open"\s*\)\s*\)\s*return/', $js)) {
    fail('backdrop handler should only run while the chat window is open');
}
if (!str_contains($js, 'setOpen(false)')) {
    fail('backdrop click should minimize the chat via setOpen(false)');
}
pass('chat window minimizes on main-page backdrop click');

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo "ALL CHECKS PASSED\n";
