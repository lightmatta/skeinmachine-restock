<?php
declare(strict_types=1);

/**
 * Demo data seeder (idempotent). Included by bin/install.php --seed.
 * Creates an admin, a staff user, vendors, catalog products, and vendor stock.
 */

use App\Database;

$pdo = Database::pdo();

// --- Users (unique email => INSERT OR IGNORE is idempotent) --------------
$users = [
    ['admin@housedye.example', 'admin12345', 'admin', 'active', 'Ada', 'Admin'],
    ['staff@housedye.example', 'staff12345', 'staff', 'active', 'Sam', 'Staff'],
];
$ustmt = $pdo->prepare(
    'INSERT OR IGNORE INTO users (email, password_hash, role, status, first_name, last_name, phone, office_address, delivery_address)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
foreach ($users as $u) {
    $ustmt->execute([
        $u[0], password_hash($u[1], PASSWORD_BCRYPT), $u[2], $u[3], $u[4], $u[5],
        '+1-555-0102-030', "12 Craft Lane\nPortland, OR 97201\nUSA", "12 Craft Lane\nPortland, OR 97201\nUSA",
    ]);
}

// --- Vendors (guard on empty table) --------------------------------------
$vcount = (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn();
if ($vcount === 0) {
    $vstmt = $pdo->prepare('INSERT INTO vendors (vendor_id, name, stock_urls, notes) VALUES (?,?,?,?)');
    $vstmt->execute(['HD-WS', 'House Dye Wholesale', "https://housedye.example/products.json\nhttps://housedye.example/collections/yarn", 'Primary yarn mill. Scrape both the catalog JSON and the yarn collection.']);
    $vstmt->execute(['FY-01', 'Fleurieu Yarn Co', 'https://fleurieuyarns.example/products.json', 'South Australian mill. One JSON feed for current stock.']);
    $vstmt->execute(['AN-01', 'Adelaide Notions', "https://adelaidenotions.example/collections/bowls\nhttps://adelaidenotions.example/collections/markers", 'Accessories supplier. Each collection URL is scraped in turn.']);
}

// --- Products (guard on empty table) -------------------------------------
$count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($count === 0) {
    $vendorIds = [];
    foreach ($pdo->query('SELECT id, vendor_id FROM vendors') as $v) {
        $vendorIds[(string)$v['vendor_id']] = (int)$v['id'];
    }
    $hd = $vendorIds['HD-WS'] ?? null;
    $fy = $vendorIds['FY-01'] ?? null;
    $an = $vendorIds['AN-01'] ?? null;
    // sku, title, description, category, price, stock, vendor, min, goal
    $products = [
        ['YRN-MERINO-01', 'Merino Sock — Coral Reef', 'Superwash merino/nylon fingering, 100g / 425m. Hand-dyed coral speckle.', 'Fingering', 2400, 4, $hd, 12, 36],
        ['YRN-MERINO-02', 'Merino Sock — Deep Ocean', 'Superwash merino/nylon fingering, tonal deep blue.', 'Fingering', 2400, 20, $hd, 10, 30],
        ['YRN-DK-01', 'Alpaca DK — Sunset', 'Baby alpaca DK, 100g / 220m, warm sunset gradient.', 'DK', 2800, 0, $hd, 8, 24],
        ['YRN-WORSTED-01', 'Corriedale Worsted — Forest', 'Rustic worsted, 100g / 180m, mossy greens.', 'Worsted', 2600, 5, $fy, 10, 20],
        ['YRN-LACE-01', 'Silk Lace — Pearl', 'Mulberry silk lace, 50g / 400m, subtle sheen.', 'Lace', 3200, 2, $fy, 6, 18],
        ['ACC-3DP-01', '3D-Printed Yarn Bowl', 'Biodegradable PLA yarn bowl with feed slot.', 'Accessories', 1800, 15, $an, 8, 20],
        ['ACC-3DP-02', '3D-Printed Shawl Pin', 'Geometric shawl pin, matte finish.', 'Accessories', 900, 3, $an, 10, 25],
        ['ACC-3DP-03', '3D-Printed Swift Spare Arms (set)', 'Replacement arms for tabletop swifts.', 'Accessories', 1500, 0, $an, 5, 12],
        ['ACC-NOTIONS-01', 'Stitch Marker Set', 'Set of 20 coral resin stitch markers.', 'Notions', 700, 40, $an, 20, 80],
    ];
    $pstmt = $pdo->prepare('INSERT INTO products (sku,title,description,category,price_cents,stock,is_public,vendor_id,min_qty,goal_qty,status) VALUES (?,?,?,?,?,?,1,?,?,?,\'active\')');
    foreach ($products as $p) {
        $pstmt->execute($p);
    }
}

// --- Vendor products (scraped catalog snapshot) --------------------------
$vpcount = (int)$pdo->query('SELECT COUNT(*) FROM vendor_products')->fetchColumn();
if ($vpcount === 0) {
    $bySku = [];
    foreach ($pdo->query('SELECT id, sku FROM products') as $p) {
        $bySku[(string)$p['sku']] = (int)$p['id'];
    }
    $vendorIds = [];
    foreach ($pdo->query('SELECT id, vendor_id FROM vendors') as $v) {
        $vendorIds[(string)$v['vendor_id']] = (int)$v['id'];
    }
    $rows = [
        ['HD-WS', 'YRN-MERINO-01', 'hd-1001', 'Merino Sock — Coral Reef', 1800, 40, 'https://housedye.example/products.json'],
        ['HD-WS', 'YRN-MERINO-02', 'hd-1002', 'Merino Sock — Deep Ocean', 1800, 18, 'https://housedye.example/products.json'],
        ['HD-WS', 'YRN-DK-01', 'hd-1003', 'Alpaca DK — Sunset', 2100, 0, 'https://housedye.example/products.json'],
        ['FY-01', 'YRN-WORSTED-01', 'fy-2001', 'Corriedale Worsted — Forest', 1900, 14, 'https://fleurieuyarns.example/products.json'],
        ['FY-01', 'YRN-LACE-01', 'fy-2002', 'Silk Lace — Pearl', 2400, 0, 'https://fleurieuyarns.example/products.json'],
        ['AN-01', 'ACC-3DP-01', 'an-3001', '3D-Printed Yarn Bowl', 1100, 22, 'https://adelaidenotions.example/collections/bowls'],
        ['AN-01', 'ACC-3DP-02', 'an-3002', '3D-Printed Shawl Pin', 500, 9, 'https://adelaidenotions.example/collections/bowls'],
        ['AN-01', 'ACC-3DP-03', 'an-3003', '3D-Printed Swift Spare Arms (set)', 900, 0, 'https://adelaidenotions.example/collections/bowls'],
        ['AN-01', 'ACC-NOTIONS-01', 'an-3004', 'Stitch Marker Set', 400, 60, 'https://adelaidenotions.example/collections/markers'],
    ];
    $vp = $pdo->prepare(
        'INSERT INTO vendor_products (vendor_id, matched_product_id, vendor_product_id, sku, title, price_cents, stock, status, source_url)
         VALUES (?,?,?,?,?,?,?,\'active\',?)'
    );
    foreach ($rows as $r) {
        $vid = $vendorIds[$r[0]] ?? 0;
        if ($vid < 1) {
            continue;
        }
        $vp->execute([$vid, $bySku[$r[1]] ?? null, $r[2], $r[1], $r[3], $r[4], $r[5], $r[6]]);
    }
    \App\RestockOrders::syncScheduleRows();
    $sched = $pdo->prepare("UPDATE restock_schedules SET starts_at = ?, ends_at = ? WHERE product_id = (SELECT id FROM products WHERE sku = ?)");
    $next = date('Y-m-d', strtotime('+3 days') ?: time());
    $sched->execute([$next, $next, 'YRN-WORSTED-01']);
}
