<?php
declare(strict_types=1);

namespace App;

/**
 * Supplier (vendor) records and scraped vendor-catalog products.
 * Matching prefers SKU, then Shopify/product id, then normalised title.
 */
class Vendors
{
    /** @return list<array<string,mixed>> */
    public static function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT id, vendor_id, name, stock_urls, notes, archived, created_at, updated_at FROM vendors';
        if (!$includeArchived) {
            $sql .= ' WHERE archived = 0';
        }
        $sql .= ' ORDER BY name COLLATE NOCASE';
        return Database::pdo()->query($sql)->fetchAll();
    }

    /** @return list<array{value:int,label:string}> */
    public static function options(): array
    {
        $out = [['value' => 0, 'label' => '—']];
        foreach (self::all() as $v) {
            $label = trim((string)$v['name']);
            $code = trim((string)($v['vendor_id'] ?? ''));
            $out[] = [
                'value' => (int)$v['id'],
                'label' => $code !== '' ? $label . ' (' . $code . ')' : $label,
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public static function urlsFrom(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $urls = [];
            foreach ($json as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $urls[] = trim($item);
                }
            }
            return array_values(array_unique($urls));
        }
        $urls = [];
        foreach (preg_split('/\r\n|\n|\r|,/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $urls[] = $line;
            }
        }
        return array_values(array_unique($urls));
    }

    public static function urlsToStorage(string $raw): string
    {
        return implode("\n", self::urlsFrom($raw));
    }

    public static function create(): int
    {
        Database::pdo()->exec("INSERT INTO vendors (vendor_id, name, stock_urls) VALUES ('', 'New vendor', '')");
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $changes
     */
    public static function update(int $id, array $changes): void
    {
        $allowed = ['vendor_id', 'name', 'stock_urls', 'notes', 'archived'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $changes)) {
                continue;
            }
            $val = $changes[$col];
            if ($col === 'stock_urls') {
                $val = self::urlsToStorage((string)$val);
            }
            if ($col === 'archived') {
                $val = (int)$val ? 1 : 0;
            }
            $sets[] = "$col = ?";
            $vals[] = $val;
        }
        if (!$sets) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        Database::pdo()->prepare('UPDATE vendors SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE products SET vendor_id = NULL WHERE vendor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM restock_schedules WHERE vendor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vendor_products WHERE vendor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM vendors WHERE id = ?')->execute([$id]);
    }

    public static function normalizeTitle(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;
        return trim((string)$title);
    }

    /**
     * Match a scraped vendor product to the catalog.
     * @return array<string,mixed>|null
     */
    public static function matchCatalogProduct(string $sku, string $productId, string $title): ?array
    {
        $pdo = Database::pdo();
        $sku = trim($sku);
        $productId = trim($productId);
        if ($sku !== '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE sku = ? AND sku != "" LIMIT 1');
            $stmt->execute([$sku]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        if ($productId !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM products WHERE shopify_product_id = ? OR CAST(id AS TEXT) = ? LIMIT 1'
            );
            $stmt->execute([$productId, $productId]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        $norm = self::normalizeTitle($title);
        if ($norm === '') {
            return null;
        }
        foreach ($pdo->query('SELECT * FROM products') as $row) {
            if (self::normalizeTitle((string)$row['title']) === $norm) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function products(array $filters = []): array
    {
        $pdo = Database::pdo();
        $sql = "SELECT vp.*, v.name AS vendor_name, v.vendor_id AS vendor_code,
                       p.title AS matched_title, p.sku AS matched_sku, p.stock AS catalog_stock
                FROM vendor_products vp
                JOIN vendors v ON v.id = vp.vendor_id
                LEFT JOIN products p ON p.id = vp.matched_product_id";
        $where = [];
        $params = [];
        if (empty($filters['include_archived'])) {
            $where[] = 'vp.archived = 0';
        }
        if (!empty($filters['vendor_id'])) {
            $where[] = 'vp.vendor_id = ?';
            $params[] = (int)$filters['vendor_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'vp.status = ?';
            $params[] = (string)$filters['status'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY v.name COLLATE NOCASE, vp.title COLLATE NOCASE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function createProduct(?int $vendorId = null): int
    {
        $vendorId = $vendorId ?: (int)(self::all()[0]['id'] ?? 0);
        Database::pdo()->prepare(
            "INSERT INTO vendor_products (vendor_id, title, status) VALUES (?, 'New vendor product', 'active')"
        )->execute([$vendorId]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $changes
     */
    public static function updateProduct(int $id, array $changes): void
    {
        $allowed = ['vendor_id', 'vendor_product_id', 'sku', 'title', 'price_cents', 'stock', 'status', 'source_url', 'matched_product_id', 'archived'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $changes)) {
                continue;
            }
            $val = $changes[$col];
            if (in_array($col, ['vendor_id', 'price_cents', 'stock', 'matched_product_id', 'archived'], true)) {
                $val = $val === '' || $val === null ? ($col === 'matched_product_id' ? null : 0) : (int)$val;
            }
            if ($col === 'status') {
                $val = (string)$val === 'inactive' ? 'inactive' : 'active';
            }
            $sets[] = "$col = ?";
            $vals[] = $val;
        }
        if (isset($changes['sku']) || isset($changes['vendor_product_id']) || isset($changes['title'])) {
            $cur = Database::pdo()->prepare('SELECT sku, vendor_product_id, title FROM vendor_products WHERE id = ?');
            $cur->execute([$id]);
            $row = $cur->fetch() ?: [];
            $sku = (string)($changes['sku'] ?? $row['sku'] ?? '');
            $pid = (string)($changes['vendor_product_id'] ?? $row['vendor_product_id'] ?? '');
            $title = (string)($changes['title'] ?? $row['title'] ?? '');
            $match = self::matchCatalogProduct($sku, $pid, $title);
            $sets[] = 'matched_product_id = ?';
            $vals[] = $match ? (int)$match['id'] : null;
        }
        if (!$sets) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        Database::pdo()->prepare('UPDATE vendor_products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    /**
     * @param list<int> $ids
     */
    public static function bulkProductStatus(array $ids, string $status): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
        $status = $status === 'inactive' ? 'inactive' : 'active';
        if (!$ids) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::pdo()->prepare(
            "UPDATE vendor_products SET status = ?, updated_at = datetime('now') WHERE id IN ($placeholders)"
        )->execute([$status, ...$ids]);
        return count($ids);
    }

    /**
     * Fetch each stock URL and upsert vendor_products.
     * @return array{ok:bool,fetched:int,created:int,updated:int,error?:string}
     */
    public static function scrape(int $vendorId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM vendors WHERE id = ?');
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch();
        if (!$vendor) {
            return ['ok' => false, 'error' => 'Vendor not found.', 'fetched' => 0, 'created' => 0, 'updated' => 0];
        }
        $urls = self::urlsFrom((string)$vendor['stock_urls']);
        if (!$urls) {
            return ['ok' => false, 'error' => 'Add at least one stock URL before scraping.', 'fetched' => 0, 'created' => 0, 'updated' => 0];
        }
        $items = [];
        foreach ($urls as $url) {
            try {
                $items = array_merge($items, self::fetchUrl($url));
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'fetched' => count($items), 'created' => 0, 'updated' => 0];
            }
        }
        $counts = self::upsertScraped($vendorId, $items);
        return ['ok' => true, 'fetched' => count($items)] + $counts;
    }

    /**
     * @return list<array{vendor_product_id:string,sku:string,title:string,price_cents:int,stock:int,source_url:string}>
     */
    public static function fetchUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Stock URL must be http(s): ' . $url);
        }
        $candidates = [$url];
        if (!str_contains($url, 'products.json')) {
            $base = rtrim(preg_replace('#\?.*$#', '', $url) ?? $url, '/');
            $candidates[] = $base . '/products.json';
            $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            $candidates[] = $host . '/products.json';
        }
        $lastError = 'No product data found.';
        foreach (array_unique($candidates) as $try) {
            try {
                $parsed = self::parseFeed($try);
                if ($parsed) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        throw new \RuntimeException($lastError);
    }

    /**
     * @return list<array{vendor_product_id:string,sku:string,title:string,price_cents:int,stock:int,source_url:string}>
     */
    private static function parseFeed(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.8'],
            CURLOPT_USERAGENT => 'SkeinmachineRestock/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException($err ?: 'Failed to fetch ' . $url);
        }
        if ($code >= 400) {
            throw new \RuntimeException('Stock URL returned HTTP ' . $code . ' (' . $url . ')');
        }
        $json = json_decode((string)$body, true);
        $products = [];
        if (is_array($json)) {
            if (isset($json['products']) && is_array($json['products'])) {
                $products = $json['products'];
            } elseif (array_is_list($json)) {
                $products = $json;
            }
        }
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $variant = $p['variants'][0] ?? [];
            $title = trim((string)($p['title'] ?? $p['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $stock = 0;
            if (isset($p['variants']) && is_array($p['variants'])) {
                foreach ($p['variants'] as $v) {
                    $stock += (int)($v['inventory_quantity'] ?? $v['stock'] ?? $v['available'] ?? 0);
                }
            } else {
                $stock = (int)($p['stock'] ?? $p['inventory_quantity'] ?? $p['available'] ?? 0);
            }
            $price = $variant['price'] ?? $p['price'] ?? 0;
            $out[] = [
                'vendor_product_id' => (string)($p['id'] ?? $p['product_id'] ?? ''),
                'sku' => (string)($variant['sku'] ?? $p['sku'] ?? ''),
                'title' => $title,
                'price_cents' => (int)round(((float)$price) * (str_contains((string)$price, '.') ? 100 : 1)),
                'stock' => $stock,
                'source_url' => $url,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{created:int,updated:int}
     */
    public static function upsertScraped(int $vendorId, array $items): array
    {
        $pdo = Database::pdo();
        $find = $pdo->prepare(
            'SELECT id FROM vendor_products WHERE vendor_id = ? AND (
                (vendor_product_id != "" AND vendor_product_id = ?)
                OR (sku != "" AND sku = ?)
                OR title = ?
             ) LIMIT 1'
        );
        $insert = $pdo->prepare(
            "INSERT INTO vendor_products (vendor_id, matched_product_id, vendor_product_id, sku, title, price_cents, stock, status, source_url)
             VALUES (?,?,?,?,?,?,?,'active',?)"
        );
        $update = $pdo->prepare(
            "UPDATE vendor_products SET matched_product_id = ?, vendor_product_id = ?, sku = ?, title = ?,
                    price_cents = ?, stock = ?, source_url = ?, updated_at = datetime('now')
             WHERE id = ?"
        );
        $created = 0;
        $updated = 0;
        foreach ($items as $it) {
            $title = trim((string)($it['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $sku = trim((string)($it['sku'] ?? ''));
            $vpid = trim((string)($it['vendor_product_id'] ?? ''));
            $match = self::matchCatalogProduct($sku, $vpid, $title);
            $find->execute([$vendorId, $vpid, $sku, $title]);
            $existing = $find->fetch();
            if ($existing) {
                $update->execute([
                    $match ? (int)$match['id'] : null,
                    $vpid, $sku, $title,
                    (int)($it['price_cents'] ?? 0),
                    (int)($it['stock'] ?? 0),
                    (string)($it['source_url'] ?? ''),
                    (int)$existing['id'],
                ]);
                $updated++;
            } else {
                $insert->execute([
                    $vendorId,
                    $match ? (int)$match['id'] : null,
                    $vpid, $sku, $title,
                    (int)($it['price_cents'] ?? 0),
                    (int)($it['stock'] ?? 0),
                    (string)($it['source_url'] ?? ''),
                ]);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated];
    }
}
