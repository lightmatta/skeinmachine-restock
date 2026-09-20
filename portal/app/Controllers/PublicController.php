<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Catalog;
use App\Database;
use App\Settings;
use App\View;

class PublicController
{
    /** Front page catalog. Wholesale clients see the same listing as /catalog. */
    public static function home(): void
    {
        View::render('public/home', array_merge(Catalog::listingContext(), [
            'title' => Settings::get('front_title', 'Welcome'),
            'intro' => Settings::get('page_products', ''),
        ]));
    }

    /** Full-page product view with the complete Shopify image gallery. */
    public static function product(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $canOrder = Auth::canOrder();
        $product = Catalog::findProduct($id, Auth::isAdmin());
        if (!$product) {
            abort(404, 'That product is not available.');
        }
        View::render('public/product', [
            'title'     => $product['title'],
            'product'   => $product,
            'images'    => Catalog::images($product, 800),
            'wholesale' => $canOrder,
            'showStock' => $canOrder && (Auth::isAdmin() || Settings::wholesaleShowStock()),
            'ignoreMin' => $canOrder && (Auth::isAdmin() || Settings::userIgnoresMin(Auth::dbUser())),
        ]);
    }

    /** Full-page bundle view: member products with their feature image only. */
    public static function bundle(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $canOrder = Auth::canOrder();
        $bundle = Catalog::findBundle($id, Auth::isAdmin());
        if (!$bundle) {
            abort(404, 'That bundle is not available.');
        }
        View::render('public/bundle', [
            'title'     => $bundle['title'],
            'bundle'    => $bundle,
            'wholesale' => $canOrder,
            'showStock' => $canOrder && (Auth::isAdmin() || Settings::wholesaleShowStock()),
            'ignoreMin' => $canOrder && (Auth::isAdmin() || Settings::userIgnoresMin(Auth::dbUser())),
        ]);
    }

    /** AJAX keyword search across title + description (public listings only). */
    public static function search(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $pdo = Database::pdo();
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            'SELECT id, title, description, category, image_url FROM products
             WHERE ' . Catalog::productVisibleSql(Auth::isAdmin()) . ' AND (title LIKE ? OR description LIKE ?)
             ORDER BY title LIMIT 200'
        );
        $stmt->execute([$like, $like]);
        $products = $stmt->fetchAll();

        $bstmt = $pdo->prepare(
            'SELECT id, title, description, image_url FROM bundles
             WHERE is_public = 1 AND archived = 0 AND (title LIKE ? OR description LIKE ?)
             ORDER BY title LIMIT 200'
        );
        $bstmt->execute([$like, $like]);
        json_response(['products' => $products, 'bundles' => $bstmt->fetchAll()]);
    }

    /** Static content pages (about/contact) edited in Settings. */
    public static function page(): void
    {
        $p = $_GET['p'] ?? 'about';
        if ($p === 'products') {
            redirect('home');
        }
        $map = ['about' => 'page_about', 'contact' => 'page_contact'];
        $key = $map[$p] ?? 'page_about';
        View::render('public/page', [
            'title' => ucfirst($p),
            'html'  => Settings::get($key, ''),
        ]);
    }

    public static function applyForm(): void
    {
        if (Settings::get('wholesale_signup_enabled', '1') !== '1') {
            View::render('public/apply_closed', ['title' => 'Applications closed']);
            return;
        }
        View::render('public/apply', [
            'title'   => 'Apply for a wholesale account',
            'preface' => Settings::get('wholesale_preface', ''),
            'error'   => $_GET['error'] ?? null,
        ]);
    }

    /** Handle a wholesale application: creates a GUEST account + password. */
    public static function applySubmit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
            redirect('apply', ['error' => 'Invalid submission. Please try again.']);
        }
        if (Settings::get('wholesale_signup_enabled', '1') !== '1') {
            redirect('home');
        }

        $f = $_POST;
        $email = strtolower(trim((string)($f['email'] ?? '')));
        $password = (string)($f['password'] ?? '');
        $required = ['first_name', 'last_name', 'email', 'phone', 'office_address', 'delivery_address'];
        foreach ($required as $r) {
            if (trim((string)($f[$r] ?? '')) === '') {
                redirect('apply', ['error' => 'Please complete all required fields.']);
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect('apply', ['error' => 'Please enter a valid email address.']);
        }
        if (strlen($password) < 8) {
            redirect('apply', ['error' => 'Password must be at least 8 characters.']);
        }

        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            redirect('apply', ['error' => 'An account with that email already exists. Please log in.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users
              (email, password_hash, role, status, first_name, last_name,
               fin_first_name, fin_last_name, phone, office_address, delivery_address,
               company_website, application_message)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            'guest',
            'pending',
            trim((string)$f['first_name']),
            trim((string)$f['last_name']),
            trim((string)($f['fin_first_name'] ?? '')),
            trim((string)($f['fin_last_name'] ?? '')),
            trim((string)$f['phone']),
            trim((string)$f['office_address']),
            trim((string)$f['delivery_address']),
            trim((string)($f['company_website'] ?? '')),
            trim((string)($f['application_message'] ?? '')),
        ]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
            ->execute([$uid, 'application', 'New wholesale application from ' . $email]);

        // Log the applicant in as a guest immediately.
        Auth::attempt($email, $password);
        redirect('dashboard');
    }
}
