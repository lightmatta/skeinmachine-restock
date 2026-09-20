<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Catalog;
use App\Database;
use App\Settings;
use App\View;

class WholesaleController
{
    /* ---------------- Dashboards ---------------- */

    public static function dashboard(): void
    {
        Auth::requireLogin();
        if (Auth::isAdmin() || Auth::isStaff()) {
            redirect('admin');
        }
        $pdo = Database::pdo();

        if (Auth::isWholesale()) {
            $uid = Auth::id();
            $orders = $pdo->prepare(
                'SELECT * FROM orders WHERE user_id = ? AND archived = 0 ORDER BY created_at DESC'
            );
            $orders->execute([$uid]);
            $orders = $orders->fetchAll();

            $pending = array_filter($orders, fn($o) => $o['status'] === 'pending');
            $active  = array_filter($orders, fn($o) => in_array($o['status'], ['provisioning', 'shipped'], true));
            $spend   = array_sum(array_map(fn($o) => (int)$o['total_cents'], $orders));

            View::render('wholesale/dashboard', [
                'title'   => 'Dashboard',
                'orders'  => $orders,
                'pending' => $pending,
                'active'  => $active,
                'spend'   => $spend,
            ]);
            return;
        }

        // Guest (pending wholesale) dashboard — no extra data beyond public.
        View::render('wholesale/guest_dashboard', ['title' => 'Account']);
    }

    /* ---------------- Catalog (wholesale only) ---------------- */

    public static function catalog(): void
    {
        Auth::requireShopper();
        // Same listing as Home: feature images, truncated descriptions, add-to-cart, optional stock.
        View::render('public/home', array_merge(Catalog::listingContext(), [
            'title' => Settings::get('front_title', 'Welcome'),
            'intro' => Settings::get('page_products', ''),
        ]));
    }

    /* ---------------- Cart ---------------- */

    /** Cart page. */
    public static function cart(): void
    {
        Auth::requireShopper();
        $cart = self::enforceMins(self::cartData(), self::ignoresMin());
        self::saveCart($cart);
        $built = self::buildLines($cart);
        View::render('wholesale/cart', [
            'title' => 'Cart',
            'lines' => $built['lines'],
            'total' => $built['total'],
            'cart'  => $cart,
        ]);
    }

    private static function cartData(): array
    {
        return $_SESSION['cart'] ?? ['products' => [], 'bundles' => []];
    }

    private static function saveCart(array $cart): void
    {
        $_SESSION['cart'] = $cart;
    }

    private static function ignoresMin(): bool
    {
        return Settings::userIgnoresMin(Auth::dbUser());
    }

    /** @param array<string,mixed> $product */
    private static function minForProduct(array $product, int $bundleMin = 1): int
    {
        if (!$product) {
            return 1;
        }
        return Settings::wholesaleFloorQty($product, self::ignoresMin(), $bundleMin);
    }

    /**
     * Raise every cart qty to the products.min_qty floor (and bundle min when higher).
     *
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    public static function enforceMins(array $cart, bool $ignoreMin): array
    {
        $pdo = Database::pdo();
        foreach ($cart['products'] ?? [] as $pid => $qty) {
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([(int)$pid]);
            $product = $p->fetch() ?: [];
            $min = Settings::wholesaleFloorQty($product, $ignoreMin);
            $cart['products'][$pid] = max($min, (int)$qty);
        }
        foreach ($cart['bundles'] ?? [] as $bid => $items) {
            foreach ($items as $pid => $qty) {
                $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $p->execute([(int)$pid]);
                $product = $p->fetch() ?: [];
                $s = $pdo->prepare('SELECT min_qty FROM bundle_items WHERE bundle_id = ? AND product_id = ?');
                $s->execute([(int)$bid, (int)$pid]);
                $bundleMin = (int)($s->fetchColumn() ?: 1);
                $min = Settings::wholesaleFloorQty($product, $ignoreMin, $bundleMin);
                $cart['bundles'][$bid][$pid] = max($min, (int)$qty);
            }
        }
        return $cart;
    }

    public static function cartAdd(): void
    {
        Auth::requireShopper();
        if (!csrf_check()) json_response(['error' => 'csrf'], 400);
        $body = request_json();
        $type = $body['type'] ?? 'product';
        $id = (int)($body['id'] ?? 0);
        $cart = self::cartData();
        $pdo = Database::pdo();

        if ($type === 'product') {
            $sql = Auth::isAdmin()
                ? 'SELECT * FROM products WHERE id = ? AND archived = 0'
                : 'SELECT * FROM products WHERE id = ? AND is_public = 1 AND archived = 0';
            $p = $pdo->prepare($sql);
            $p->execute([$id]);
            $product = $p->fetch();
            if (!$product) {
                json_response(['error' => 'unavailable', 'message' => 'That product is not currently available.'], 400);
            }
            $qty = max(1, (int)($body['qty'] ?? 1));
            $min = self::minForProduct($product);
            $next = ($cart['products'][$id] ?? 0) + $qty;
            $cart['products'][$id] = max($min, $next);
        } else {
            $bchk = $pdo->prepare('SELECT is_public, archived FROM bundles WHERE id = ?');
            $bchk->execute([$id]);
            $bundle = $bchk->fetch();
            if (!$bundle || (int)$bundle['archived'] === 1 || ((int)$bundle['is_public'] !== 1 && !Auth::isAdmin())) {
                json_response(['error' => 'unavailable', 'message' => 'That bundle is not currently available.'], 400);
            }
            // Seed each available member at max(products.min_qty, bundle min).
            $extra = Auth::isAdmin()
                ? 'AND p.archived = 0'
                : 'AND p.is_public = 1 AND p.archived = 0';
            $stmt = $pdo->prepare(
                "SELECT bi.product_id, bi.min_qty AS bundle_min_qty, p.min_qty, p.id, p.title
                 FROM bundle_items bi
                 JOIN products p ON p.id = bi.product_id
                 WHERE bi.bundle_id = ? {$extra}"
            );
            $stmt->execute([$id]);
            $items = [];
            foreach ($stmt->fetchAll() as $it) {
                $items[(int)$it['product_id']] = Catalog::itemFloorQty($it, self::ignoresMin());
            }
            if ($items) {
                $cart['bundles'][$id] = $items;
            }
        }
        $cart = self::enforceMins($cart, self::ignoresMin());
        self::saveCart($cart);
        json_response(['ok' => true, 'count' => self::count($cart)]);
    }

    public static function cartUpdate(): void
    {
        Auth::requireShopper();
        if (!csrf_check()) json_response(['error' => 'csrf'], 400);
        $body = request_json();
        $scope = $body['scope'] ?? 'product';
        $qty = max(0, (int)($body['qty'] ?? 0));
        $cart = self::cartData();
        $pdo = Database::pdo();

        if ($scope === 'product') {
            $id = (int)($body['id'] ?? 0);
            if ($qty <= 0) {
                unset($cart['products'][$id]);
            } else {
                $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $p->execute([$id]);
                $product = $p->fetch() ?: [];
                $min = self::minForProduct($product);
                $newQty = max($min, $qty);
                $cart['products'][$id] = $newQty;
                if ($qty < $min) {
                    self::saveCart($cart);
                    json_response(['ok' => true, 'clamped' => true, 'min' => $min, 'qty' => $newQty, 'count' => self::count($cart)]);
                }
            }
        } else { // bundleitem
            $bid = (int)($body['bundle_id'] ?? 0);
            $pid = (int)($body['product_id'] ?? 0);
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([$pid]);
            $product = $p->fetch() ?: [];
            $s = $pdo->prepare('SELECT min_qty FROM bundle_items WHERE bundle_id = ? AND product_id = ?');
            $s->execute([$bid, $pid]);
            $bundleMin = (int)($s->fetchColumn() ?: 1);
            $min = self::minForProduct($product, $bundleMin);
            if (isset($cart['bundles'][$bid][$pid])) {
                if ($qty <= 0) {
                    unset($cart['bundles'][$bid][$pid]);
                    if (empty($cart['bundles'][$bid])) {
                        unset($cart['bundles'][$bid]);
                    }
                } else {
                    $newQty = max($min, $qty);
                    $cart['bundles'][$bid][$pid] = $newQty;
                    if ($qty < $min) {
                        self::saveCart($cart);
                        json_response(['ok' => true, 'clamped' => true, 'min' => $min, 'qty' => $newQty, 'count' => self::count($cart)]);
                    }
                }
            }
        }
        $cart = self::enforceMins($cart, self::ignoresMin());
        self::saveCart($cart);
        json_response(['ok' => true, 'count' => self::count($cart)]);
    }

    public static function cartRemove(): void
    {
        Auth::requireShopper();
        if (!csrf_check()) json_response(['error' => 'csrf'], 400);
        $body = request_json();
        $cart = self::cartData();
        if (($body['scope'] ?? '') === 'bundle') {
            unset($cart['bundles'][(int)($body['id'] ?? 0)]);
        } else {
            unset($cart['products'][(int)($body['id'] ?? 0)]);
        }
        self::saveCart($cart);
        json_response(['ok' => true, 'count' => self::count($cart)]);
    }

    public static function cartCount(): int
    {
        return self::count(self::cartData());
    }

    private static function count(array $cart): int
    {
        $n = count($cart['products'] ?? []);
        foreach ($cart['bundles'] ?? [] as $items) $n += count($items);
        return $n;
    }

    /** Build detailed cart line items with prices from the DB. */
    private static function buildLines(array $cart): array
    {
        $pdo = Database::pdo();
        $lines = [];
        $total = 0;

        foreach ($cart['products'] ?? [] as $pid => $qty) {
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([(int)$pid]);
            $row = $p->fetch();
            if (!$row || (int)$row['archived'] === 1) {
                continue;
            }
            if (!Auth::isAdmin() && (int)$row['is_public'] !== 1) {
                continue;
            }
            $lt = Settings::shopperWholesaleCents((int)$row['price_cents']) * (int)$qty;
            $total += $lt;
            $lines[] = [
                'scope' => 'product', 'product_id' => (int)$pid, 'bundle_id' => null,
                'title' => $row['title'], 'qty' => (int)$qty,
                'min' => self::minForProduct($row),
                'unit' => Settings::shopperWholesaleCents((int)$row['price_cents']), 'line_total' => $lt, 'stock' => (int)$row['stock'],
                'image' => Catalog::sizedUrl(Catalog::featureImage($row), 400),
            ];
        }
        foreach ($cart['bundles'] ?? [] as $bid => $items) {
            $bs = $pdo->prepare('SELECT title FROM bundles WHERE id = ?');
            $bs->execute([(int)$bid]);
            $bTitle = $bs->fetchColumn() ?: 'Bundle';
            foreach ($items as $pid => $qty) {
                $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $p->execute([(int)$pid]);
                $row = $p->fetch();
                if (!$row || (int)$row['archived'] === 1) {
                    continue;
                }
                if (!Auth::isAdmin() && (int)$row['is_public'] !== 1) {
                    continue;
                }
                $s = $pdo->prepare('SELECT min_qty FROM bundle_items WHERE bundle_id = ? AND product_id = ?');
                $s->execute([(int)$bid, (int)$pid]);
                $bundleMin = (int)($s->fetchColumn() ?: 1);
                $lt = Settings::shopperWholesaleCents((int)$row['price_cents']) * (int)$qty;
                $total += $lt;
                $lines[] = [
                    'scope' => 'bundle', 'product_id' => (int)$pid, 'bundle_id' => (int)$bid,
                    'title' => '[' . $bTitle . '] ' . $row['title'], 'qty' => (int)$qty,
                    'min' => self::minForProduct($row, $bundleMin),
                    'unit' => Settings::shopperWholesaleCents((int)$row['price_cents']), 'line_total' => $lt, 'stock' => (int)$row['stock'],
                    'image' => Catalog::sizedUrl(Catalog::featureImage($row), 400),
                ];
            }
        }
        return ['lines' => $lines, 'total' => $total];
    }

    public static function checkout(): void
    {
        Auth::requireShopper();
        $cart = self::enforceMins(self::cartData(), self::ignoresMin());
        self::saveCart($cart);
        $built = self::buildLines($cart);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_check()) redirect('cart');
            if (!$built['lines']) redirect('cart');
            $pdo = Database::pdo();
            $notes = trim((string)($_POST['notes'] ?? ''));
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO orders (user_id, status, payment_status, notes, total_cents) VALUES (?,?,?,?,?)');
            $ins->execute([Auth::shopperId(), 'pending', 'pending', $notes, $built['total']]);
            $oid = (int)$pdo->lastInsertId();
            $li = $pdo->prepare('INSERT INTO order_items (order_id, product_id, bundle_id, title, qty, unit_price_cents, line_total_cents) VALUES (?,?,?,?,?,?,?)');
            foreach ($built['lines'] as $l) {
                $li->execute([$oid, $l['product_id'], $l['bundle_id'], $l['title'], $l['qty'], $l['unit'], $l['line_total']]);
            }
            $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
                ->execute([Auth::shopperId(), 'order', 'New order #' . $oid . ' (' . money($built['total']) . ')']);
            $pdo->commit();
            unset($_SESSION['cart']);
            redirect('order', ['id' => $oid, 'placed' => 1]);
        }

        View::render('wholesale/checkout', [
            'title' => 'Checkout',
            'lines' => $built['lines'],
            'total' => $built['total'],
        ]);
    }

    public static function orders(): void
    {
        Auth::requireShopper();
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([Auth::shopperId()]);
        View::render('wholesale/orders', ['title' => 'My orders', 'orders' => $stmt->fetchAll()]);
    }

    /** Load an order enforcing per-client ownership. */
    private static function ownedOrder(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, Auth::shopperId()]);
        return $stmt->fetch() ?: null;
    }

    public static function orderView(): void
    {
        Auth::requireShopper();
        $id = (int)($_GET['id'] ?? 0);
        $order = self::ownedOrder($id);
        if (!$order) abort(404, 'Order not found.');
        $items = Database::pdo()->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$id]);
        View::render('wholesale/order_view', [
            'title'  => 'Order #' . $id,
            'order'  => $order,
            'items'  => $items->fetchAll(),
            'placed' => isset($_GET['placed']),
            'canModify' => $order['status'] === 'pending',
        ]);
    }

    public static function orderCancel(): void
    {
        Auth::requireShopper();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) redirect('orders');
        $id = (int)($_POST['id'] ?? 0);
        $order = self::ownedOrder($id);
        // Once provisioning, clients cannot modify/delete — they must contact us.
        if ($order && $order['status'] === 'pending') {
            Database::pdo()->prepare("UPDATE orders SET status = 'cancelled', updated_at = datetime('now') WHERE id = ? AND user_id = ?")
                ->execute([$id, Auth::shopperId()]);
            \App\Alerts::log(Auth::shopperId(), 'order_cancelled', 'Order #' . $id . ' was cancelled');
        }
        redirect('order', ['id' => $id]);
    }

    public static function orderPrint(): void
    {
        Auth::requireLogin();
        $id = (int)($_GET['id'] ?? 0);
        $pdo = Database::pdo();
        // Admins may print any order; wholesale clients only their own.
        if (Auth::isAdmin()) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$id]);
        } else {
            Auth::requireShopper();
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, Auth::shopperId()]);
        }
        $order = $stmt->fetch();
        if (!$order) abort(404, 'Order not found.');
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$id]);
        $cust = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $cust->execute([(int)$order['user_id']]);
        View::renderRaw('wholesale/order_print', [
            'order' => $order,
            'items' => $items->fetchAll(),
            'cust'  => $cust->fetch() ?: [],
            'company' => Settings::get('company_name', 'HouseDye'),
            'contactEmail' => Settings::get('contact_email', ''),
            'contactPhone' => Settings::get('contact_phone', ''),
        ]);
    }
}
