<?php
declare(strict_types=1);

/**
 * Demo data seeder (idempotent). Included by bin/install.php --seed.
 * Creates an admin, a wholesale client, products, and a bundle.
 */

use App\Database;

$pdo = Database::pdo();

// --- Users (unique email => INSERT OR IGNORE is idempotent) --------------
$users = [
    ['admin@housedye.example', 'admin12345', 'admin', 'active', 'Ada', 'Admin', 0],
    ['staff@housedye.example', 'staff12345', 'staff', 'active', 'Sam', 'Staff', 0],
    ['shop@example.com', 'shop12345', 'wholesale', 'active', 'Wendy', 'Wholesale', 0],
    ['freehand@example.com', 'shop12345', 'wholesale', 'active', 'Frank', 'Freehand', 1],
    ['pending@example.com', 'guest12345', 'guest', 'pending', 'Gerry', 'Guest', 0],
];
$ustmt = $pdo->prepare(
    'INSERT OR IGNORE INTO users (email, password_hash, role, status, first_name, last_name, ignore_min_quantities, phone, office_address, delivery_address, approved_at)
     VALUES (?,?,?,?,?,?,?,?,?,?, CASE WHEN ?="active" THEN datetime("now") ELSE NULL END)'
);
foreach ($users as $u) {
    $ustmt->execute([
        $u[0], password_hash($u[1], PASSWORD_BCRYPT), $u[2], $u[3], $u[4], $u[5], $u[6],
        '+1-555-0102-030', "12 Craft Lane\nPortland, OR 97201\nUSA", "12 Craft Lane\nPortland, OR 97201\nUSA",
        $u[3],
    ]);
}

// --- Products (guard on empty table) -------------------------------------
$count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($count === 0) {
    $products = [
        ['YRN-MERINO-01', 'Merino Sock — Coral Reef', 'Superwash merino/nylon fingering, 100g / 425m. Hand-dyed coral speckle.', 'Fingering', 2400, 60],
        ['YRN-MERINO-02', 'Merino Sock — Deep Ocean', 'Superwash merino/nylon fingering, tonal deep blue.', 'Fingering', 2400, 45],
        ['YRN-DK-01', 'Alpaca DK — Sunset', 'Baby alpaca DK, 100g / 220m, warm sunset gradient.', 'DK', 2800, 30],
        ['YRN-WORSTED-01', 'Corriedale Worsted — Forest', 'Rustic worsted, 100g / 180m, mossy greens.', 'Worsted', 2600, 25],
        ['YRN-LACE-01', 'Silk Lace — Pearl', 'Mulberry silk lace, 50g / 400m, subtle sheen.', 'Lace', 3200, 18],
        ['ACC-3DP-01', '3D-Printed Yarn Bowl', 'Biodegradable PLA yarn bowl with feed slot.', 'Accessories', 1800, 40],
        ['ACC-3DP-02', '3D-Printed Shawl Pin', 'Geometric shawl pin, matte finish.', 'Accessories', 900, 80],
        ['ACC-3DP-03', '3D-Printed Swift Spare Arms (set)', 'Replacement arms for tabletop swifts.', 'Accessories', 1500, 22],
        ['ACC-NOTIONS-01', 'Stitch Marker Set', 'Set of 20 coral resin stitch markers.', 'Notions', 700, 120],
    ];
    $pstmt = $pdo->prepare('INSERT INTO products (sku,title,description,category,price_cents,stock,is_public) VALUES (?,?,?,?,?,?,1)');
    foreach ($products as $p) $pstmt->execute($p);
}

// --- Bundle (guard on empty table) ---------------------------------------
$bcount = (int)$pdo->query('SELECT COUNT(*) FROM bundles')->fetchColumn();
if ($bcount === 0) {
    $pdo->prepare('INSERT INTO bundles (title, description, is_public) VALUES (?,?,1)')
        ->execute(['Beginner Bundle', 'A curated starter set for new stockists: best-selling yarns plus maker accessories.']);
    $bid = (int)$pdo->lastInsertId();
    // product_id => min_qty  (first 5 products + a couple of accessories)
    $items = [1 => 6, 2 => 6, 3 => 4, 6 => 3, 7 => 5, 9 => 10];
    $bi = $pdo->prepare('INSERT INTO bundle_items (bundle_id, product_id, min_qty, sort_order) VALUES (?,?,?,?)');
    $order = 0;
    foreach ($items as $pid => $min) $bi->execute([$bid, $pid, $min, $order++]);
}
