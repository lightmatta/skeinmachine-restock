<?php
declare(strict_types=1);

/**
 * Front controller. All requests enter here. Routing uses the `r` query
 * parameter (e.g. index.php?r=admin/orders) for maximum portability across
 * Apache/nginx without rewrite rules. A .htaccess is provided for pretty URLs.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth;
use App\Controllers\PublicController;
use App\Controllers\AuthController;
use App\Controllers\WholesaleController;
use App\Controllers\AdminController;
use App\Controllers\MessageController;
use App\Controllers\ApiController;

$route  = $_GET['r'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];

// Refresh admin presence on any authenticated admin request.
Auth::touchAdminPresence();

try {
    switch ($route) {
        // ---- Public ----------------------------------------------------
        case 'home':            PublicController::home(); break;
        case 'page':            PublicController::page(); break;
        case 'search':          PublicController::search(); break;          // AJAX
        case 'product':         PublicController::product(); break;
        case 'bundle':          PublicController::bundle(); break;
        case 'apply':           PublicController::applyForm(); break;
        case 'apply.submit':    PublicController::applySubmit(); break;

        // ---- Auth ------------------------------------------------------
        case 'login':           AuthController::loginForm(); break;
        case 'login.submit':    AuthController::login(); break;
        case 'logout':          AuthController::logout(); break;
        case 'account':         AuthController::account(); break;
        case 'account.save':    AuthController::accountSave(); break;

        // ---- Wholesale -------------------------------------------------
        case 'dashboard':       WholesaleController::dashboard(); break;
        case 'catalog':         WholesaleController::catalog(); break;
        case 'cart':            WholesaleController::cart(); break;
        case 'cart.add':        WholesaleController::cartAdd(); break;       // AJAX
        case 'cart.update':     WholesaleController::cartUpdate(); break;    // AJAX
        case 'cart.remove':     WholesaleController::cartRemove(); break;    // AJAX
        case 'checkout':        WholesaleController::checkout(); break;
        case 'orders':          WholesaleController::orders(); break;
        case 'order':           WholesaleController::orderView(); break;
        case 'order.cancel':    WholesaleController::orderCancel(); break;
        case 'order.print':     WholesaleController::orderPrint(); break;

        // ---- Messaging (chatbox) --------------------------------------
        case 'chat.poll':       MessageController::poll(); break;           // AJAX
        case 'chat.send':       MessageController::send(); break;           // AJAX
        case 'chat.alerts':     MessageController::alerts(); break;         // AJAX
        case 'chat.recipients': MessageController::recipients(); break;     // AJAX

        // ---- Admin -----------------------------------------------------
        case 'admin':           AdminController::dashboard(); break;
        case 'admin/users':     AdminController::users(); break;
        case 'admin/orders':    AdminController::orders(); break;
        case 'admin/products':  AdminController::products(); break;
        case 'admin/bundles':   AdminController::bundles(); break;
        case 'admin/messages':  AdminController::messages(); break;
        case 'admin/work-orders': AdminController::workOrders(); break;
        case 'admin/work-orders/schedule': AdminController::workOrdersSchedule(); break;
        case 'admin/analytics': AdminController::analytics(); break;
        case 'admin/settings':  AdminController::settings(); break;
        case 'admin/shopify-csv': AdminController::shopifyCsv(); break;

        // Admin AJAX/data endpoints
        case 'admin/api':       AdminController::api(); break;

        // ---- Mobile / external JSON API -------------------------------
        case 'api':             ApiController::handle(); break;

        default:
            abort(404, 'Page not found.');
    }
} catch (\Throwable $ex) {
    // Log server-side; show a generic message.
    error_log('[portal] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (($_GET['format'] ?? '') === 'json' || $route === 'api' || $route === 'admin/api') {
        json_response(['error' => 'server_error', 'message' => 'An unexpected error occurred.'], 500);
    }
    abort(500, 'An unexpected error occurred.');
}
