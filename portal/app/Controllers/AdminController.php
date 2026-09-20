<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Catalog;
use App\Database;
use App\Settings;
use App\ShopifyCsv;
use App\UserPrefs;
use App\View;
use App\WorkOrders;
use App\Nlp\QueryEngine;
use PDO;

class AdminController
{
    /** Editable/creatable column whitelist per grid entity. */
    private const SCHEMA = [
        'orders' => [
            'table' => 'orders',
            'editable' => ['status', 'payment_status', 'tracking_url', 'notes', 'admin_notes', 'total_cents', 'manual_discount_cents'],
            'archivable' => true,
        ],
        'products' => [
            'table' => 'products',
            'editable' => ['sku', 'title', 'description', 'category', 'price_cents', 'stock', 'min_qty', 'spt', 'warehouse_stock', 'is_public', 'archived', 'colours'],
            'archivable' => true,
        ],
        'bundles' => [
            'table' => 'bundles',
            'editable' => ['title', 'description', 'is_public', 'archived'],
            'archivable' => true,
        ],
        'users' => [
            'table' => 'users',
            'editable' => ['email', 'role', 'status', 'ignore_min_quantities', 'tray_rate', 'discount_percent', 'first_name', 'last_name',
                           'fin_first_name', 'fin_last_name', 'phone', 'company_website',
                           'office_address', 'delivery_address'],
            'archivable' => false,
        ],
    ];

    /* ================= Pages ================= */

    public static function dashboard(): void
    {
        Auth::requireStaffOrAdmin();
        $pdo = Database::pdo();

        if (Auth::isStaff()) {
            $all = WorkOrders::list();
            $uid = Auth::id();
            $assigned = array_values(array_filter($all, fn($w) => (int)($w['staff_user_id'] ?? 0) === $uid));
            $pending = array_values(array_filter($all, fn($w) => !WorkOrders::isDone((string)($w['status'] ?? ''))));
            View::render('admin/staff_dashboard', [
                'title' => 'Dashboard', 'active' => 'dashboard',
                'assigned' => $assigned, 'open' => $pending,
            ]);
            return;
        }

        $pendingApps = $pdo->query(
            "SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC"
        )->fetchAll();

        // New orders sorted with payment pending first, then newest.
        $newOrders = $pdo->query(
            "SELECT o.*, u.email AS client_email FROM orders o JOIN users u ON u.id = o.user_id
             WHERE o.archived = 0
             ORDER BY (o.payment_status = 'pending') DESC, o.created_at DESC LIMIT 15"
        )->fetchAll();

        // Threads with unread (client/staff) messages awaiting admin reply.
        $unread = $pdo->query(
            "SELECT m.thread_user_id, u.email, COUNT(*) AS unread, MAX(m.created_at) AS last_at
             FROM messages m JOIN users u ON u.id = m.thread_user_id
             WHERE m.sender IN ('client','staff') AND m.read_by_admin = 0
               AND (m.to_user_id IS NULL OR m.to_user_id IN (SELECT id FROM users WHERE role = 'admin'))
             GROUP BY m.thread_user_id ORDER BY last_at DESC"
        )->fetchAll();

        $activity = $pdo->query('SELECT * FROM activity ORDER BY created_at DESC LIMIT 20')->fetchAll();

        $stats = [
            'orders'    => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'pending_pay' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status='pending' AND status<>'cancelled'")->fetchColumn(),
            'clients'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='wholesale'")->fetchColumn(),
            'revenue'   => (int)$pdo->query("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE payment_status='paid'")->fetchColumn(),
        ];

        View::render('admin/dashboard', [
            'title' => 'Admin', 'active' => 'dashboard',
            'pendingApps' => $pendingApps, 'newOrders' => $newOrders,
            'unread' => $unread, 'activity' => $activity, 'stats' => $stats,
            'readyOrders' => Auth::isSuperuser() ? WorkOrders::readyForApproval() : [],
            'unassignedOrders' => WorkOrders::unassignedProvisioning(),
            'stalledWork' => WorkOrders::stalledItems(),
        ]);
    }

    public static function users(): void
    {
        Auth::requireAdmin();
        $pending = Database::pdo()->query(
            "SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC"
        )->fetchAll();
        View::render('admin/users', ['title' => 'Users', 'active' => 'users', 'pending' => $pending,
            'canGrantAdmin' => Auth::isSuperuser(),
            'hiddenCols' => UserPrefs::hiddenColumns(Auth::id(), UserPrefs::GRID_USERS, [])]);
    }

    public static function orders(): void
    {
        Auth::requireAdmin();
        $pdo = Database::pdo();
        $clients = $pdo->query("SELECT id, email FROM users WHERE role IN ('wholesale','guest','admin') ORDER BY email")->fetchAll();
        View::render('admin/orders', [
            'title' => 'Orders', 'active' => 'orders', 'clients' => $clients,
            'hiddenCols' => UserPrefs::hiddenColumns(Auth::id(), UserPrefs::GRID_ORDERS, ['notes']),
        ]);
    }

    public static function products(): void
    {
        Auth::requireStaffOrAdmin();
        View::render('admin/products', [
            'title' => 'Products', 'active' => 'products',
            'readonly' => Auth::isStaff(),
            'wholesalePercent' => Settings::wholesalePercent(),
            'hiddenCols' => UserPrefs::hiddenColumns(
                Auth::id(),
                UserPrefs::GRID_PRODUCTS,
                ['description', 'shopify_product_id']
            ),
        ]);
    }

    public static function bundles(): void
    {
        Auth::requireStaffOrAdmin();
        $pdo = Database::pdo();
        $bundles = $pdo->query('SELECT * FROM bundles ORDER BY title')->fetchAll();
        $items = $pdo->query(
            'SELECT bi.*, p.title, p.price_cents FROM bundle_items bi JOIN products p ON p.id = bi.product_id
             ORDER BY bi.bundle_id, bi.sort_order, bi.id'
        )->fetchAll();
        $byBundle = [];
        foreach ($items as $it) { $byBundle[(int)$it['bundle_id']][] = $it; }
        $products = $pdo->query('SELECT id, title, price_cents FROM products WHERE archived = 0 ORDER BY title')->fetchAll();
        View::render('admin/bundles', ['title' => 'Bundles', 'active' => 'bundles',
            'bundles' => $bundles, 'byBundle' => $byBundle, 'products' => $products,
            'readonly' => Auth::isStaff()]);
    }

    public static function workOrders(): void
    {
        Auth::requireStaffOrAdmin();
        $rows = WorkOrders::listGrouped();
        $groups = [];
        foreach ($rows as $w) {
            $oid = (int)$w['order_id'];
            if (!isset($groups[$oid])) {
                $groups[$oid] = [
                    'client_name'  => $w['client_name'],
                    'client_email' => $w['client_email'],
                    'items'        => [],
                    'all_complete' => true,
                ];
            }
            $groups[$oid]['items'][] = $w;
            $lineDone = WorkOrders::isDone((string)$w['status']);
            foreach ($w['trays'] ?? [] as $tray) {
                if (!WorkOrders::isDone((string)$tray['status'])) {
                    $lineDone = false;
                }
            }
            if (!$lineDone) {
                $groups[$oid]['all_complete'] = false;
            }
        }
        $staff = Database::pdo()->query(
            "SELECT id, email, first_name, last_name FROM users WHERE role = 'staff' AND status = 'active' ORDER BY first_name, email"
        )->fetchAll();
        View::render('admin/work_orders', [
            'title' => 'Work orders', 'active' => 'work-orders',
            'rows' => $rows, 'groups' => $groups, 'staff' => $staff,
            'isSuper' => Auth::isSuperuser(), 'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public static function workOrdersSchedule(): void
    {
        Auth::requireStaffOrAdmin();
        $view = UserPrefs::normalizeGanttView(UserPrefs::getJson(Auth::id(), UserPrefs::GANTT_VIEW, []));
        $fromGet = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
        $toGet = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
        $from = $fromGet !== '' ? $fromGet : ($view['from'] ?? date('Y-m-d'));
        $to = $toGet !== '' ? $toGet : ($view['to'] ?? date('Y-m-d', strtotime('+21 days')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) {
            $from = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) {
            $to = date('Y-m-d', strtotime('+21 days'));
        }
        if ($to < $from) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }
        View::render('admin/work_orders_schedule', [
            'title' => 'Work order schedule',
            'active' => 'work-orders-schedule',
            'from' => $from,
            'to' => $to,
            'orders' => WorkOrders::provisioningOrders(),
            'rows' => WorkOrders::listGrouped(),
            'view' => $view,
            'isAdmin' => Auth::isAdmin(),
            'staffRates' => WorkOrders::staffRates(),
            'conflicts' => WorkOrders::scheduleConflicts(),
        ]);
    }

    public static function messages(): void
    {
        Auth::requireAdmin();
        View::render('admin/messages', ['title' => 'Messages', 'active' => 'messages']);
    }

    public static function analytics(): void
    {
        Auth::requireAdmin();
        View::render('admin/analytics', ['title' => 'Analytics', 'active' => 'analytics']);
    }

    public static function settings(): void
    {
        Auth::requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_check()) redirect('admin/settings');
            $keys = ['company_name', 'front_title', 'highlight_color', 'logo_url', 'contact_email',
                     'contact_phone', 'opening_hours', 'wholesale_preface', 'fy_start_month',
                     'page_about', 'page_contact', 'page_products',
                     'shopify_domain', 'shopify_client_id', 'shopify_api_version', 'shopify_collection_id'];
            $prevClientId = (string)Settings::get('shopify_client_id', '');
            $prevDomain = (string)Settings::get('shopify_domain', '');
            foreach ($keys as $k) {
                if (array_key_exists($k, $_POST)) {
                    Settings::set($k, (string)$_POST[$k]);
                }
            }
            // Client secret is write-only: only overwrite when a new value is supplied.
            $secretUpdated = false;
            if (isset($_POST['shopify_client_secret']) && trim((string)$_POST['shopify_client_secret']) !== '') {
                Settings::set('shopify_client_secret', trim((string)$_POST['shopify_client_secret']));
                $secretUpdated = true;
            }
            $newClientId = (string)Settings::get('shopify_client_id', '');
            $newDomain = (string)Settings::get('shopify_domain', '');
            if ($secretUpdated || $newClientId !== $prevClientId || $newDomain !== $prevDomain) {
                \App\ShopifyService::forgetCachedToken();
            }
            Settings::set('wholesale_signup_enabled', isset($_POST['wholesale_signup_enabled']) ? '1' : '0');
            Settings::set('wholesale_show_stock', isset($_POST['wholesale_show_stock']) ? '1' : '0');
            Settings::set('im_browser_notifications', isset($_POST['im_browser_notifications']) ? '1' : '0');
            Settings::set('admin_event_alerts', isset($_POST['admin_event_alerts']) ? '1' : '0');
            Settings::set('detect_colours_on_import', isset($_POST['detect_colours_on_import']) ? '1' : '0');
            $prevPeriodic = Settings::get('shopify_periodic_sync', '0');
            $prevMins = Settings::get('shopify_periodic_minutes', '60');
            Settings::set('shopify_periodic_sync', isset($_POST['shopify_periodic_sync']) ? '1' : '0');
            if (array_key_exists('shopify_periodic_minutes', $_POST)) {
                $mins = (int)$_POST['shopify_periodic_minutes'];
                Settings::set('shopify_periodic_minutes', (string)max(1, min(24 * 60, $mins ?: 60)));
            }
            $newPeriodic = Settings::get('shopify_periodic_sync', '0');
            $newMins = Settings::get('shopify_periodic_minutes', '60');
            if ($newPeriodic !== $prevPeriodic || $newMins !== $prevMins) {
                \App\ShopifyService::schedulePeriodicFromNow();
            }
            if (array_key_exists('wholesale_percent', $_POST)) {
                $pct = (int)$_POST['wholesale_percent'];
                Settings::set('wholesale_percent', (string)max(0, min(100, $pct)));
            }
            if (array_key_exists('product_min_qty', $_POST)) {
                $min = (int)$_POST['product_min_qty'];
                Settings::set('product_min_qty', (string)max(1, $min ?: 10));
            }
            if (array_key_exists('product_spt', $_POST)) {
                $spt = (int)$_POST['product_spt'];
                Settings::set('product_spt', (string)max(1, $spt ?: 10));
            }
            if (array_key_exists('currency', $_POST)) {
                Settings::set('currency', \App\Currency::normalize((string)$_POST['currency']));
            }
            redirect('admin/settings', ['saved' => 1]);
        }
        View::render('admin/settings', ['title' => 'Settings', 'active' => 'settings',
            'settings' => Settings::all(), 'saved' => isset($_GET['saved'])]);
    }

    /** Shopify product CSV upload (alternative to the Admin API sync). */
    public static function shopifyCsv(): void
    {
        Auth::requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
            json_response(['ok' => false, 'error' => 'Invalid submission.'], 400);
        }
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'error' => 'Choose a Shopify product CSV to import.'], 400);
        }
        if ((int)$file['size'] > 12 * 1024 * 1024) {
            json_response(['ok' => false, 'error' => 'CSV is too large (12 MB limit).'], 400);
        }
        $name = strtolower((string)($file['name'] ?? ''));
        if ($name !== '' && !str_ends_with($name, '.csv')) {
            json_response(['ok' => false, 'error' => 'Please upload a .csv file exported from Shopify.'], 400);
        }
        $csv = file_get_contents($file['tmp_name']);
        if ($csv === false) {
            json_response(['ok' => false, 'error' => 'Could not read the uploaded file.'], 400);
        }
        $result = ShopifyCsv::import($csv);
        if ($result['ok'] ?? false) {
            Database::pdo()->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
                ->execute([
                    Auth::id(),
                    'products',
                    'Shopify CSV import: ' . (int)$result['created'] . ' created, ' . (int)$result['updated'] . ' updated',
                ]);
        }
        json_response($result, ($result['ok'] ?? false) ? 200 : 400);
    }

    /* ================= Unified AJAX API ================= */

    public static function api(): void
    {
        Auth::requireLogin();
        if (!Auth::isStaffOrAdmin()) {
            json_response(['error' => 'forbidden'], 403);
        }
        if (!csrf_check()) json_response(['error' => 'csrf'], 400);
        $body = request_json();
        $entity = (string)($body['entity'] ?? '');
        $op = (string)($body['op'] ?? '');

        if ($entity === 'work_orders') { self::workOrdersApi($op, $body); }
        if ($entity === 'prefs') { self::prefsApi($op, $body); }

        if (Auth::isStaff()) {
            $allowed = ['products' => ['list'], 'bundles' => ['list']];
            if (!isset($allowed[$entity]) || !in_array($op, $allowed[$entity], true)) {
                json_response(['error' => 'forbidden', 'message' => 'Staff accounts have read-only catalog access.'], 403);
            }
        }

        // Special (non-grid) entities (admin only beyond this point, except list above).
        if ($entity === 'messages') { self::messagesApi($op, $body); }
        if ($entity === 'analytics') { self::analyticsApi($op, $body); }
        if ($entity === 'bundle_items') { self::bundleItemsApi($op, $body); }
        if ($entity === 'user_action') { self::userActionApi($op, $body); }
        if ($entity === 'shopify') { self::shopifyApi($op, $body); }
        if ($entity === 'settings') { self::settingsApi($op, $body); }

        if (!isset(self::SCHEMA[$entity])) {
            json_response(['error' => 'unknown_entity'], 400);
        }
        $schema = self::SCHEMA[$entity];
        $pdo = Database::pdo();

        switch ($op) {
            case 'list':
                json_response(['rows' => self::listRows($entity, $body['filters'] ?? [])]);
                break;

            case 'create':
                $id = self::createRow($entity);
                json_response(['ok' => true, 'id' => $id]);
                break;

            case 'update':
                $id = (int)($body['id'] ?? 0);
                $changes = (array)($body['changes'] ?? []);
                // Only the superuser may elevate a user to the admin role.
                if ($entity === 'users' && isset($changes['role'])) {
                    $role = (string)$changes['role'];
                    if (!in_array($role, ['guest', 'wholesale', 'staff', 'admin'], true)) {
                        json_response(['error' => 'bad_role'], 400);
                    }
                    if ($role === 'admin' && !Auth::isSuperuser()) {
                        json_response(['error' => 'forbidden', 'message' => 'Only the superuser can grant administrator privileges.'], 403);
                    }
                }
                $sets = [];
                $vals = [];
                // Password is typed in clear text in the users grid, then hashed here.
                // The hash is never returned to the browser.
                if ($entity === 'users' && array_key_exists('password', $changes)) {
                    $plain = (string)$changes['password'];
                    unset($changes['password']);
                    if (trim($plain) !== '') {
                        if (strlen($plain) < 8) {
                            json_response(['error' => 'short_password', 'message' => 'Password must be at least 8 characters.'], 400);
                        }
                        $sets[] = 'password_hash = ?';
                        $vals[] = password_hash($plain, PASSWORD_BCRYPT);
                    }
                }
                if ($entity === 'users' && array_key_exists('email', $changes)) {
                    $email = strtolower(trim((string)$changes['email']));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        json_response(['error' => 'bad_email', 'message' => 'Enter a valid email address.'], 400);
                    }
                    $dup = $pdo->prepare('SELECT id FROM users WHERE lower(email) = ? AND id <> ? LIMIT 1');
                    $dup->execute([$email, $id]);
                    if ($dup->fetch()) {
                        json_response(['error' => 'duplicate_email', 'message' => 'That email is already in use.'], 400);
                    }
                    $changes['email'] = $email;
                }
                if ($entity === 'products' && array_key_exists('min_qty', $changes)) {
                    $changes['min_qty'] = max(1, (int)$changes['min_qty']);
                }
                if ($entity === 'products' && array_key_exists('spt', $changes)) {
                    $changes['spt'] = max(1, (int)$changes['spt']);
                }
                if ($entity === 'products' && array_key_exists('warehouse_stock', $changes)) {
                    $changes['warehouse_stock'] = max(0, (int)$changes['warehouse_stock']);
                }
                if ($entity === 'products' && (array_key_exists('colours', $changes) || array_key_exists('variegated', $changes))) {
                    $cur = $pdo->prepare('SELECT colours FROM products WHERE id = ?');
                    $cur->execute([$id]);
                    $current = (string)$cur->fetchColumn();
                    $raw = array_key_exists('colours', $changes) ? (string)$changes['colours'] : $current;
                    $list = array_values(array_filter(
                        Catalog::parseColours($raw),
                        static fn(string $c): bool => $c !== 'variegated'
                    ));
                    $flag = array_key_exists('variegated', $changes)
                        ? ((int)$changes['variegated'] ? 1 : 0)
                        : (Catalog::productHasColour(['colours' => $current], 'variegated') ? 1 : 0);
                    if ($flag) {
                        $list[] = 'variegated';
                    }
                    $changes['colours'] = implode(',', $list);
                    unset($changes['variegated']);
                }
                if ($entity === 'users' && array_key_exists('tray_rate', $changes)) {
                    $changes['tray_rate'] = max(1, (int)$changes['tray_rate']);
                }
                if ($entity === 'users' && array_key_exists('discount_percent', $changes)) {
                    $changes['discount_percent'] = max(0, min(100, (int)$changes['discount_percent']));
                }
                if ($entity === 'orders' && array_key_exists('manual_discount_cents', $changes)) {
                    $changes['manual_discount_cents'] = max(0, (int)$changes['manual_discount_cents']);
                }
                if ($entity === 'bundles' && array_key_exists('title', $changes)) {
                    $title = trim((string)$changes['title']);
                    if ($title === '') {
                        json_response(['error' => 'bad_title', 'message' => 'Bundle name cannot be empty.'], 400);
                    }
                    $changes['title'] = $title;
                }
                foreach ($changes as $col => $val) {
                    if (!in_array($col, $schema['editable'], true)) continue;
                    $sets[] = "$col = ?";
                    $vals[] = $val;
                }
                if (!$sets) json_response(['error' => 'no_editable_fields', 'message' => 'Nothing to update.'], 400);
                if (in_array('updated_at', self::columns($schema['table']), true)) {
                    $sets[] = "updated_at = datetime('now')";
                }
                $oldStatus = null;
                if ($entity === 'orders' && array_key_exists('status', $changes)) {
                    $prev = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
                    $prev->execute([$id]);
                    $oldStatus = (string)$prev->fetchColumn();
                }
                $vals[] = $id;
                $pdo->prepare("UPDATE {$schema['table']} SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                if ($entity === 'orders' && array_key_exists('manual_discount_cents', $changes)) {
                    self::refreshOrderTotals($id);
                }
                if ($entity === 'orders' && array_key_exists('status', $changes)) {
                    WorkOrders::onStatusChange($id, $oldStatus ?? '', (string)$changes['status']);
                    $newStatus = (string)$changes['status'];
                    if ($newStatus !== ($oldStatus ?? '')) {
                        $owner = $pdo->prepare('SELECT user_id FROM orders WHERE id = ?');
                        $owner->execute([$id]);
                        $clientId = (int)$owner->fetchColumn();
                        if ($newStatus === 'cancelled') {
                            \App\Alerts::log($clientId, 'order_cancelled', 'Order #' . $id . ' was cancelled');
                        } elseif ($newStatus === 'completed') {
                            \App\Alerts::log($clientId, 'order_completed', 'Order #' . $id . ' was completed');
                        }
                    }
                }
                json_response(['ok' => true]);
                break;

            case 'items':
                if ($entity !== 'orders') {
                    json_response(['error' => 'unknown_op'], 400);
                }
                json_response(self::orderItemsPayload((int)($body['id'] ?? 0)));
                break;

            case 'add_item':
            case 'update_item':
            case 'delete_item':
                if ($entity !== 'orders') {
                    json_response(['error' => 'unknown_op'], 400);
                }
                self::mutateOrderItem($op, $body);
                break;

            case 'create_bundle':
                if ($entity !== 'orders') {
                    json_response(['error' => 'unknown_op'], 400);
                }
                json_response(self::createBundleFromOrder((int)($body['id'] ?? 0)));
                break;

            case 'bulk_update':
                if ($entity !== 'products') {
                    json_response(['error' => 'unknown_op'], 400);
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])), fn($n) => $n > 0)));
                $changes = (array)($body['changes'] ?? []);
                $sets = [];
                $vals = [];
                foreach (['is_public', 'spt', 'warehouse_stock'] as $col) {
                    if (!array_key_exists($col, $changes)) continue;
                    if ($col === 'is_public') {
                        $changes[$col] = (int)$changes[$col] ? 1 : 0;
                    }
                    if ($col === 'spt') {
                        $changes[$col] = max(1, (int)$changes[$col]);
                    }
                    if ($col === 'warehouse_stock') {
                        $changes[$col] = max(0, (int)$changes[$col]);
                    }
                    $sets[] = "$col = ?";
                    $vals[] = $changes[$col];
                }
                if (!$ids || !$sets) {
                    json_response(['error' => 'no_editable_fields', 'message' => 'Select products and a value to apply.'], 400);
                }
                $sets[] = "updated_at = datetime('now')";
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . " WHERE id IN ($placeholders)")
                    ->execute([...$vals, ...$ids]);
                json_response(['ok' => true, 'updated' => count($ids)]);
                break;

            case 'delete':
                $id = (int)($body['id'] ?? 0);
                if ($entity === 'bundles') {
                    if (empty($body['confirm'])) {
                        json_response(['error' => 'confirmation_required', 'message' => 'Confirm that this cannot be undone.'], 400);
                    }
                    $pdo->beginTransaction();
                    try {
                        Catalog::deleteBundle($id);
                        $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
                            ->execute([Auth::id(), 'bundles', 'Deleted bundle #' . $id]);
                        $pdo->commit();
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        json_response(['error' => 'delete_failed', 'message' => 'Could not delete the bundle.'], 500);
                    }
                    json_response(['ok' => true]);
                }
                if ($entity === 'products') {
                    $pdo->prepare('UPDATE order_items SET product_id = NULL WHERE product_id = ?')->execute([$id]);
                }
                $pdo->prepare("DELETE FROM {$schema['table']} WHERE id = ?")->execute([$id]);
                json_response(['ok' => true]);
                break;

            case 'delete_all':
                if ($entity !== 'products') {
                    json_response(['error' => 'unknown_op', 'message' => 'Bulk delete is only available for products.'], 400);
                }
                if (empty($body['confirm'])) {
                    json_response(['error' => 'confirmation_required', 'message' => 'Confirm that this cannot be undone.'], 400);
                }
                $pdo->beginTransaction();
                try {
                    $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
                    $pdo->exec('UPDATE order_items SET product_id = NULL');
                    $pdo->exec('DELETE FROM bundle_items');
                    $pdo->exec('DELETE FROM products');
                    $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
                        ->execute([Auth::id(), 'products', 'Deleted all products (' . $count . ')']);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    json_response(['error' => 'delete_failed', 'message' => 'Could not delete products.'], 500);
                }
                json_response(['ok' => true, 'deleted' => $count]);
                break;

            case 'archive':
                if ($schema['archivable']) {
                    $pdo->prepare("UPDATE {$schema['table']} SET archived = 1 WHERE id = ?")->execute([(int)($body['id'] ?? 0)]);
                } elseif ($entity === 'users') {
                    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([(int)($body['id'] ?? 0)]);
                }
                json_response(['ok' => true]);
                break;

            default:
                json_response(['error' => 'unknown_op'], 400);
        }
    }

    private static function listRows(string $entity, array $filters): array
    {
        $pdo = Database::pdo();
        if ($entity === 'orders') {
            $where = [];
            $params = [];
            if (!empty($filters['status'])) { $where[] = 'o.status = ?'; $params[] = $filters['status']; }
            if (!empty($filters['user_id'])) { $where[] = 'o.user_id = ?'; $params[] = (int)$filters['user_id']; }
            if (!empty($filters['from'])) { $where[] = 'date(o.created_at) >= ?'; $params[] = $filters['from']; }
            if (!empty($filters['to'])) { $where[] = 'date(o.created_at) <= ?'; $params[] = $filters['to']; }
            if (empty($filters['include_archived'])) { $where[] = 'o.archived = 0'; }
            $sql = "SELECT o.id, u.email AS client, o.status, o.payment_status, o.total_cents,
                           o.manual_discount_cents, u.discount_percent, o.tracking_url, o.notes, o.admin_notes, o.archived, o.created_at
                    FROM orders o JOIN users u ON u.id = o.user_id";
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY (o.payment_status = "pending") DESC, o.created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }
        if ($entity === 'products') {
            $rows = $pdo->query('SELECT id, sku, title, description, category, price_cents, stock, min_qty, spt, warehouse_stock, is_public, status, shopify_product_id, archived, colours FROM products ORDER BY title')->fetchAll();
            foreach ($rows as &$r) {
                $r['wholesale_cents'] = Settings::wholesaleCents((int)$r['price_cents']);
                $r['colours'] = Catalog::coloursCsv((string)($r['colours'] ?? ''));
                $r['variegated'] = Catalog::productHasColour($r, 'variegated') ? 1 : 0;
            }
            unset($r);
            return $rows;
        }
        if ($entity === 'bundles') {
            return $pdo->query('SELECT id, title, description, is_public, archived FROM bundles ORDER BY title')->fetchAll();
        }
        if ($entity === 'users') {
            return $pdo->query(
                "SELECT id, email, role, status, first_name, last_name, phone, company_website,
                        ignore_min_quantities, tray_rate, discount_percent, created_at,
                        CASE WHEN password_hash IS NOT NULL AND password_hash <> '' THEN 1 ELSE 0 END AS has_password
                 FROM users WHERE id > 0 ORDER BY created_at DESC"
            )->fetchAll();
        }
        return [];
    }

    private static function createRow(string $entity): int
    {
        $pdo = Database::pdo();
        if ($entity === 'products') {
            $min = Settings::productMinQty();
            $spt = Settings::productSpt();
            $pdo->prepare("INSERT INTO products (title, price_cents, stock, is_public, min_qty, spt, warehouse_stock) VALUES ('New product', 0, 0, 0, ?, ?, 0)")
                ->execute([$min, $spt]);
        } elseif ($entity === 'bundles') {
            $pdo->exec("INSERT INTO bundles (title, is_public) VALUES ('New bundle', 0)");
        } elseif ($entity === 'users') {
            $email = 'user' . time() . '@example.com';
            $pdo->prepare("INSERT INTO users (email, role, status) VALUES (?, 'guest', 'active')")->execute([$email]);
        } elseif ($entity === 'orders') {
            // Orders are created by clients; block manual creation to preserve integrity.
            json_response(['error' => 'Orders are created by clients at checkout.'], 400);
        }
        return (int)$pdo->lastInsertId();
    }

    /** @return array{items:list<array<string,mixed>>,total_cents:int,products:list<array<string,mixed>>} */
    private static function orderItemsPayload(int $orderId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, product_id, title, qty, unit_price_cents, line_total_cents FROM order_items WHERE order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        $order = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        $order = $order->fetch() ?: [];
        $totals = order_totals($order, $items);
        $cust = self::orderCustomer($orderId);
        $products = $pdo->query(
            "SELECT id, title, price_cents, min_qty FROM products WHERE archived = 0 ORDER BY title"
        )->fetchAll();
        foreach ($products as &$p) {
            $p['wholesale_cents'] = Settings::wholesaleCents((int)$p['price_cents'], $cust);
        }
        unset($p);
        return [
            'items' => $items,
            'total_cents' => $totals['total'],
            'subtotal_cents' => $totals['subtotal'],
            'manual_discount_cents' => $totals['discount'],
            'products' => $products,
        ];
    }

    /**
     * Snapshot an order's product lines into a hidden "New bundle".
     *
     * @return array{ok:bool,bundle_id?:int,title?:string,items?:int,error?:string,message?:string}
     */
    private static function createBundleFromOrder(int $orderId): array
    {
        $pdo = Database::pdo();
        $order = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        if (!$order->fetch()) {
            return ['error' => 'not_found', 'message' => 'Order not found.'];
        }
        $stmt = $pdo->prepare(
            'SELECT product_id, qty FROM order_items WHERE order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        $seen = [];
        $members = [];
        foreach ($stmt->fetchAll() as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qty = max(1, (int)$it['qty']);
            if (isset($seen[$pid])) {
                $members[$seen[$pid]]['min_qty'] += $qty;
                continue;
            }
            $exists = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $exists->execute([$pid]);
            if (!$exists->fetch()) {
                continue;
            }
            $seen[$pid] = count($members);
            $members[] = ['product_id' => $pid, 'min_qty' => $qty];
        }
        if (!$members) {
            return ['error' => 'empty', 'message' => 'This order has no catalog products to copy into a bundle.'];
        }
        $pdo->prepare("INSERT INTO bundles (title, is_public, archived) VALUES ('New bundle', 0, 0)")->execute();
        $bid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO bundle_items (bundle_id, product_id, min_qty, sort_order) VALUES (?,?,?,?)'
        );
        foreach ($members as $i => $m) {
            $ins->execute([$bid, $m['product_id'], $m['min_qty'], $i]);
        }
        return [
            'ok' => true,
            'bundle_id' => $bid,
            'title' => 'New bundle',
            'items' => count($members),
            'message' => 'Created hidden bundle “New bundle” with ' . count($members) . ' product' . (count($members) === 1 ? '' : 's') . '.',
        ];
    }

    private static function mutateOrderItem(string $op, array $body): void
    {
        $pdo = Database::pdo();
        $oid = (int)($body['order_id'] ?? $body['id'] ?? 0);
        if ($op !== 'add_item') {
            $itemId = (int)($body['item_id'] ?? 0);
            $found = $pdo->prepare('SELECT * FROM order_items WHERE id = ?');
            $found->execute([$itemId]);
            $item = $found->fetch();
            if (!$item) {
                json_response(['error' => 'not_found', 'message' => 'Line item not found.'], 404);
            }
            $oid = (int)$item['order_id'];
        }
        if ($oid <= 0) {
            json_response(['error' => 'bad_order'], 400);
        }
        $order = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
        $order->execute([$oid]);
        if (!$order->fetch()) {
            json_response(['error' => 'not_found', 'message' => 'Order not found.'], 404);
        }

        if ($op === 'add_item') {
            $pid = (int)($body['product_id'] ?? 0);
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([$pid]);
            $product = $p->fetch();
            if (!$product) {
                json_response(['error' => 'unknown_product', 'message' => 'Choose a product to add.'], 400);
            }
            $qty = max(1, (int)($body['qty'] ?? 1));
            $unit = array_key_exists('unit_price_cents', $body)
                ? max(0, (int)$body['unit_price_cents'])
                : Settings::wholesaleCents((int)$product['price_cents'], self::orderCustomer($oid));
            $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, title, qty, unit_price_cents, line_total_cents)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$oid, $pid, (string)$product['title'], $qty, $unit, $unit * $qty]);
        } elseif ($op === 'update_item') {
            $qty = max(1, (int)($body['qty'] ?? $item['qty']));
            $unit = array_key_exists('unit_price_cents', $body)
                ? max(0, (int)$body['unit_price_cents'])
                : (int)$item['unit_price_cents'];
            $title = array_key_exists('title', $body) ? trim((string)$body['title']) : (string)$item['title'];
            if ($title === '') {
                $title = (string)$item['title'];
            }
            $pdo->prepare(
                'UPDATE order_items SET title = ?, qty = ?, unit_price_cents = ?, line_total_cents = ? WHERE id = ?'
            )->execute([$title, $qty, $unit, $unit * $qty, (int)$item['id']]);
        } else {
            $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([(int)$item['id']]);
        }

        self::refreshOrderTotals($oid);
        json_response(array_merge(['ok' => true], self::orderItemsPayload($oid)));
    }

    private static function refreshOrderTotals(int $orderId): void
    {
        $pdo = Database::pdo();
        $sum = $pdo->prepare('SELECT COALESCE(SUM(line_total_cents),0) FROM order_items WHERE order_id = ?');
        $sum->execute([$orderId]);
        $subtotal = (int)$sum->fetchColumn();
        $disc = $pdo->prepare('SELECT COALESCE(manual_discount_cents,0) FROM orders WHERE id = ?');
        $disc->execute([$orderId]);
        $total = max(0, $subtotal - max(0, (int)$disc->fetchColumn()));
        $pdo->prepare("UPDATE orders SET total_cents = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$total, $orderId]);
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        if ((string)$st->fetchColumn() === 'provisioning') {
            WorkOrders::syncFromOrder($orderId, false);
        }
    }

    /** @return array<string,mixed>|null */
    private static function orderCustomer(int $orderId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.* FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?'
        );
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    private static function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $cols = [];
        foreach (Database::pdo()->query("PRAGMA table_info($table)") as $c) {
            $cols[] = $c['name'];
        }
        return $cache[$table] = $cols;
    }

    /* ---------- Messages ---------- */

    private static function messagesApi(string $op, array $body): void
    {
        $pdo = Database::pdo();
        if ($op === 'recipients') {
            $rows = $pdo->query(
                "SELECT id, email, role, first_name, last_name FROM users WHERE status IN ('active','pending') ORDER BY role, email"
            )->fetchAll();
            json_response(['recipients' => array_map(static function (array $u): array {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                return [
                    'id' => (int)$u['id'],
                    'email' => $u['email'],
                    'role' => $u['role'],
                    'name' => $name !== '' ? $name : $u['email'],
                ];
            }, $rows)]);
        }
        if ($op === 'threads') {
            $rows = $pdo->query(
                "SELECT u.id, u.email, u.first_name, u.last_name, u.role,
                        (SELECT body FROM messages m2 WHERE m2.thread_user_id = u.id ORDER BY id DESC LIMIT 1) AS last_body,
                        (SELECT created_at FROM messages m3 WHERE m3.thread_user_id = u.id ORDER BY id DESC LIMIT 1) AS last_at,
                        (SELECT COUNT(*) FROM messages m4 WHERE m4.thread_user_id = u.id AND m4.sender IN ('client','staff') AND m4.read_by_admin=0) AS unread
                 FROM users u
                 WHERE EXISTS (SELECT 1 FROM messages m WHERE m.thread_user_id = u.id OR m.to_user_id = u.id)
                 ORDER BY unread DESC, last_at DESC"
            )->fetchAll();
            json_response(['threads' => $rows]);
        }
        if ($op === 'thread') {
            $uid = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT id, sender, body, created_at, sender_user_id FROM messages WHERE thread_user_id = ? OR to_user_id = ? ORDER BY id'
            );
            $stmt->execute([$uid, $uid]);
            $pdo->prepare("UPDATE messages SET read_by_admin = 1 WHERE (thread_user_id = ? OR to_user_id = ?) AND sender IN ('client','staff')")->execute([$uid, $uid]);
            $msgs = array_map(fn($m) => [
                'id' => (int)$m['id'], 'sender' => $m['sender'], 'body' => $m['body'], 'time' => fmt_datetime($m['created_at']),
                'sender_user_id' => isset($m['sender_user_id']) ? (int)$m['sender_user_id'] : null,
            ], $stmt->fetchAll());
            json_response(['messages' => $msgs]);
        }
        if ($op === 'reply') {
            $uid = (int)($body['id'] ?? 0);
            $text = trim((string)($body['body'] ?? ''));
            if ($uid && $text !== '') {
                $pdo->prepare(
                    "INSERT INTO messages (thread_user_id, sender, body, read_by_admin, read_by_client, to_user_id, sender_user_id)
                     VALUES (?, 'admin', ?, 1, 0, NULL, ?)"
                )->execute([$uid, mb_substr($text, 0, 2000), Auth::id()]);
            }
            json_response(['ok' => true]);
        }
        if ($op === 'compose') {
            $uid = (int)($body['id'] ?? 0);
            $text = trim((string)($body['body'] ?? ''));
            if ($uid && $text !== '') {
                $exists = $pdo->prepare('SELECT id FROM users WHERE id = ?');
                $exists->execute([$uid]);
                if (!$exists->fetch()) {
                    json_response(['error' => 'unknown_user'], 400);
                }
                $pdo->prepare(
                    "INSERT INTO messages (thread_user_id, sender, body, read_by_admin, read_by_client, to_user_id, sender_user_id)
                     VALUES (?, 'admin', ?, 1, 0, ?, ?)"
                )->execute([$uid, mb_substr($text, 0, 2000), $uid, Auth::id()]);
            }
            json_response(['ok' => true]);
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    private static function workOrdersApi(string $op, array $body): void
    {
        if ($op === 'list') {
            json_response(['rows' => WorkOrders::listAll(), 'grouped' => WorkOrders::listGrouped(), 'orders' => WorkOrders::provisioningOrders()]);
        }
        if ($op === 'update') {
            $id = (int)($body['id'] ?? 0);
            $changes = (array)($body['changes'] ?? []);
            if (array_key_exists('status', $changes)) {
                $status = (string)$changes['status'];
                if (!in_array($status, WorkOrders::STATUSES, true)) {
                    json_response(['error' => 'bad_status'], 400);
                }
                $note = array_key_exists('notes', $changes) ? (string)$changes['notes'] : null;
                if ($status === 'stalled' && trim((string)$note) === '') {
                    $cur = Database::pdo()->prepare('SELECT notes FROM work_orders WHERE id = ?');
                    $cur->execute([$id]);
                    $existingNote = trim((string)$cur->fetchColumn());
                    if ($existingNote === '') {
                        json_response(['error' => 'note_required', 'message' => 'Add a note explaining why this work is stalled.'], 400);
                    }
                    $note = $existingNote;
                }
                WorkOrders::setItemStatus($id, $status, $note);
            } elseif (array_key_exists('notes', $changes)) {
                WorkOrders::setItemNote($id, (string)$changes['notes']);
            }
            if (array_key_exists('qty', $changes)) {
                if (!WorkOrders::setItemQty($id, (int)$changes['qty'])) {
                    json_response(['error' => 'bad_qty', 'message' => 'Only tray sub-tasks can change quantity.'], 400);
                }
            }
            if (array_key_exists('starts_at', $changes) || array_key_exists('ends_at', $changes)) {
                $cur = Database::pdo()->prepare('SELECT starts_at, ends_at FROM work_orders WHERE id = ?');
                $cur->execute([$id]);
                $row = $cur->fetch() ?: ['starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d')];
                $start = array_key_exists('starts_at', $changes) ? (string)$changes['starts_at'] : (string)($row['starts_at'] ?: date('Y-m-d'));
                $end = array_key_exists('ends_at', $changes) ? (string)$changes['ends_at'] : (string)($row['ends_at'] ?: $start);
                if (!WorkOrders::setSchedule($id, $start, $end)) {
                    json_response(['error' => 'bad_dates', 'message' => 'Use YYYY-MM-DD dates for the schedule.'], 400);
                }
            }
            if (array_key_exists('staff_user_id', $changes)) {
                if (!Auth::isAdmin()) {
                    json_response(['error' => 'forbidden', 'message' => 'Only administrators assign staff.'], 403);
                }
                $sid = (int)$changes['staff_user_id'];
                WorkOrders::assignStaff($id, $sid > 0 ? $sid : null);
            }
            json_response(['ok' => true]);
        }
        if ($op === 'schedule_batch') {
            $items = $body['items'] ?? [];
            if (!is_array($items) || !$items) {
                json_response(['error' => 'bad_items', 'message' => 'Nothing to schedule.'], 400);
            }
            $n = WorkOrders::setScheduleBatch($items);
            if ($n < 1) {
                json_response(['error' => 'bad_dates', 'message' => 'Use YYYY-MM-DD dates for the schedule.'], 400);
            }
            json_response(['ok' => true, 'updated' => $n, 'conflicts' => WorkOrders::scheduleConflicts()]);
        }
        if ($op === 'auto_schedule') {
            if (!Auth::isAdmin()) {
                json_response(['error' => 'forbidden', 'message' => 'Only administrators auto-schedule work.'], 403);
            }
            $mode = (string)($body['mode'] ?? 'orders');
            $orderIds = array_map('intval', (array)($body['order_ids'] ?? []));
            $unitIds = array_map('intval', (array)($body['unit_ids'] ?? []));
            if ($mode === 'conflicts') {
                if (!$unitIds) {
                    foreach (WorkOrders::scheduleConflicts() as $c) {
                        foreach ($c['ids'] as $id) {
                            $unitIds[] = (int)$id;
                        }
                    }
                }
                if (!$unitIds) {
                    json_response(['error' => 'no_conflicts', 'message' => 'There are no tray-rate conflicts to resolve.'], 400);
                }
                json_response(WorkOrders::autoSchedule([], $unitIds));
            }
            if (!$orderIds) {
                json_response(['error' => 'bad_orders', 'message' => 'Select one or more orders to auto-schedule.'], 400);
            }
            json_response(WorkOrders::autoSchedule($orderIds, []));
        }
        if ($op === 'restore_schedule') {
            if (!Auth::isAdmin()) {
                json_response(['error' => 'forbidden', 'message' => 'Only administrators restore a schedule.'], 403);
            }
            $items = $body['items'] ?? [];
            if (!is_array($items) || !$items) {
                json_response(['error' => 'bad_items', 'message' => 'Nothing to restore.'], 400);
            }
            $n = WorkOrders::restoreDates($items);
            json_response(['ok' => true, 'updated' => $n, 'conflicts' => WorkOrders::scheduleConflicts()]);
        }
        if ($op === 'assign_order') {
            if (!Auth::isAdmin()) {
                json_response(['error' => 'forbidden'], 403);
            }
            $oid = (int)($body['id'] ?? 0);
            $sid = (int)($body['staff_user_id'] ?? 0);
            WorkOrders::assignStaffToOrder($oid, $sid > 0 ? $sid : null);
            json_response(['ok' => true]);
        }
        if ($op === 'approve') {
            if (!Auth::isSuperuser()) {
                json_response(['error' => 'forbidden', 'message' => 'Only the Super Admin can approve a completed order.'], 403);
            }
            $ok = WorkOrders::approveOrder((int)($body['id'] ?? 0));
            if (!$ok) {
                json_response(['error' => 'not_ready', 'message' => 'Every work-order item must be complete, and the order must still be provisioning.'], 400);
            }
            json_response(['ok' => true]);
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    /* ---------- Per-user UI prefs ---------- */

    private static function prefsApi(string $op, array $body): void
    {
        $key = (string)($body['key'] ?? '');
        if (!in_array($key, UserPrefs::PREF_KEYS, true)) {
            json_response(['error' => 'unknown_pref'], 400);
        }
        $uid = Auth::id();
        if ($op === 'get') {
            $val = UserPrefs::getJson($uid, $key, null);
            if ($key === UserPrefs::GANTT_VIEW && $val !== null) {
                $val = UserPrefs::normalizeGanttView($val);
            }
            json_response(['ok' => true, 'key' => $key, 'value' => $val]);
        }
        if ($op === 'set') {
            $value = $body['value'] ?? [];
            if (!is_array($value)) {
                json_response(['error' => 'bad_value'], 400);
            }
            if ($key === UserPrefs::GANTT_VIEW) {
                $clean = UserPrefs::normalizeGanttView($value);
                UserPrefs::set($uid, $key, $clean);
                json_response(['ok' => true, 'key' => $key, 'value' => $clean]);
            }
            $clean = [];
            foreach ($value as $col) {
                if (is_string($col) && $col !== '') {
                    $clean[] = $col;
                }
            }
            UserPrefs::set($uid, $key, array_values(array_unique($clean)));
            json_response(['ok' => true, 'key' => $key, 'value' => $clean]);
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    /* ---------- Settings ---------- */

    private static function settingsApi(string $op, array $body): void
    {
        if ($op === 'set') {
            $key = (string)($body['key'] ?? '');
            $allowed = ['wholesale_show_stock', 'detect_colours_on_import'];
            if (!in_array($key, $allowed, true)) {
                json_response(['error' => 'unknown_setting'], 400);
            }
            Settings::set($key, !empty($body['value']) ? '1' : '0');
            json_response(['ok' => true, 'value' => Settings::get($key)]);
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    private static function shopifyApi(string $op, array $body): void
    {
        if ($op === 'test') {
            json_response(\App\ShopifyService::testConnection());
        }
        if ($op === 'sync') {
            $result = \App\ShopifyService::sync();
            json_response($result, ($result['ok'] ?? false) ? 200 : 400);
        }
        if ($op === 'tick') {
            json_response(\App\ShopifyService::tickPeriodic());
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    /* ---------- Analytics ---------- */

    private static function analyticsApi(string $op, array $body): void
    {
        if ($op === 'query') {
            $engine = new QueryEngine();
            json_response($engine->run((string)($body['q'] ?? '')));
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    /* ---------- Bundle items ---------- */

    private static function bundleItemsApi(string $op, array $body): void
    {
        $pdo = Database::pdo();
        $bid = (int)($body['bundle_id'] ?? 0);
        if ($op === 'add') {
            $pid = (int)($body['product_id'] ?? 0);
            $min = max(1, (int)($body['min_qty'] ?? 1));
            if (!$bid || !$pid) {
                json_response(['error' => 'bad_request', 'message' => 'Choose a product to add.'], 400);
            }
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM bundle_items WHERE bundle_id = ?');
            $maxStmt->execute([$bid]);
            $next = (int)$maxStmt->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty, sort_order) VALUES (?,?,?,?)')
                ->execute([$bid, $pid, $min, $next]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->prepare(
                'SELECT bi.id, bi.bundle_id, bi.product_id, bi.min_qty, bi.sort_order, p.title, p.price_cents
                 FROM bundle_items bi JOIN products p ON p.id = bi.product_id WHERE bi.id = ?'
            );
            $row->execute([$id]);
            json_response(['ok' => true, 'item' => $row->fetch()]);
        }
        if ($op === 'update') {
            $pdo->prepare('UPDATE bundle_items SET min_qty = ? WHERE id = ?')
                ->execute([max(1, (int)($body['min_qty'] ?? 1)), (int)($body['id'] ?? 0)]);
            json_response(['ok' => true]);
        }
        if ($op === 'reorder') {
            if (!Catalog::reorderBundleItems($bid, (array)($body['ids'] ?? []))) {
                json_response(['error' => 'bad_order', 'message' => 'Could not save the new order'], 400);
            }
            json_response(['ok' => true]);
        }
        if ($op === 'delete') {
            $pdo->prepare('DELETE FROM bundle_items WHERE id = ?')->execute([(int)($body['id'] ?? 0)]);
            json_response(['ok' => true]);
        }
        json_response(['error' => 'unknown_op'], 400);
    }

    /* ---------- User actions (approval / privileges) ---------- */

    private static function userActionApi(string $op, array $body): void
    {
        $pdo = Database::pdo();
        $uid = (int)($body['id'] ?? 0);

        if ($op === 'approve') {
            // Approve a wholesale application: guest -> wholesale, active.
            $ignoreMin = to_bool_int($body['ignore_min_quantities'] ?? 0);
            $pdo->prepare("UPDATE users SET role = 'wholesale', status = 'active', ignore_min_quantities = ?, approved_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
                ->execute([$ignoreMin, $uid]);
            $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
                ->execute([$uid, 'approval', 'Wholesale account approved']);
            json_response(['ok' => true]);
        }

        if ($op === 'set_role') {
            $role = (string)($body['role'] ?? 'guest');
            $allowed = ['guest', 'wholesale', 'staff', 'admin'];
            if (!in_array($role, $allowed, true)) json_response(['error' => 'bad_role'], 400);
            // Only the superuser may grant/revoke ADMIN (superuser-level) privileges.
            if ($role === 'admin' && !Auth::isSuperuser()) {
                json_response(['error' => 'Only the superuser can grant administrator privileges.'], 403);
            }
            $pdo->prepare("UPDATE users SET role = ?, updated_at = datetime('now') WHERE id = ?")->execute([$role, $uid]);
            json_response(['ok' => true]);
        }

        if ($op === 'set_status') {
            $status = (string)($body['status'] ?? 'active');
            if (!in_array($status, ['active', 'pending', 'disabled'], true)) json_response(['error' => 'bad_status'], 400);
            $pdo->prepare("UPDATE users SET status = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $uid]);
            json_response(['ok' => true]);
        }

        if ($op === 'toggle_ignore_min') {
            $pdo->prepare('UPDATE users SET ignore_min_quantities = 1 - ignore_min_quantities WHERE id = ?')->execute([$uid]);
            json_response(['ok' => true]);
        }

        json_response(['error' => 'unknown_op'], 400);
    }
}
