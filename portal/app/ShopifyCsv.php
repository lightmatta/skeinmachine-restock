<?php
declare(strict_types=1);

namespace App;

/**
 * Import products from a Shopify Admin "Export products" CSV.
 *
 * Only portal-relevant columns are used. Products are grouped by Handle
 * (Shopify's product identity in the export). Existing rows are overwritten
 * when the portal id, Shopify/handle id or SKU matches. Retail Status is
 * refreshed from the CSV; Wholesale Status (is_public) is preserved on
 * existing rows and only set for newly created products.
 */
class ShopifyCsv
{
    /**
     * Parse a Shopify product CSV and upsert into the catalog.
     * @return array{ok:bool,created:int,updated:int,skipped:int,error?:string}
     */
    public static function import(string $csv): array
    {
        $csv = self::stripBom($csv);
        if (trim($csv) === '') {
            return ['ok' => false, 'error' => 'The CSV file is empty.', 'created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        try {
            $rows = self::readRows($csv);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'created' => 0, 'updated' => 0, 'skipped' => 0];
        }
        if (!$rows) {
            return ['ok' => false, 'error' => 'No data rows found in the CSV.', 'created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $grouped = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $handle = (string)($row['Handle'] ?? '');
            if ($handle === '') {
                $skipped++;
                continue;
            }
            $grouped[$handle][] = $row;
        }
        if (!$grouped) {
            return ['ok' => false, 'error' => 'No products with a Handle column were found.', 'created' => 0, 'updated' => 0, 'skipped' => $skipped];
        }

        $items = [];
        foreach ($grouped as $handle => $group) {
            $items[] = self::productFromRows($handle, $group);
        }

        try {
            $result = self::upsert($items);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not save imported products.', 'created' => 0, 'updated' => 0, 'skipped' => $skipped];
        }
        $result['ok'] = true;
        $result['skipped'] = $skipped;
        Settings::set('shopify_last_csv_import', date('c'));
        return $result;
    }

    /** @return list<array<string,string>> */
    public static function readRows(string $csv): array
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not read the CSV.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh);
        if (!is_array($header) || !$header) {
            fclose($fh);
            throw new \RuntimeException('The CSV is missing a header row.');
        }
        $header = array_map(static function ($h) {
            $h = (string)$h;
            if (str_starts_with($h, "\xEF\xBB\xBF")) {
                $h = substr($h, 3);
            }
            return trim($h);
        }, $header);

        $out = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = isset($data[$i]) ? (string)$data[$i] : '';
            }
            $out[] = $row;
        }
        fclose($fh);
        return $out;
    }

    /** Collapse Shopify's one-row-per-variant/image format into one portal product. */
    public static function productFromRows(string $handle, array $group): array
    {
        $title = '';
        $body = '';
        $type = '';
        $sku = '';
        $price = '';
        $qty = 0;
        $published = '';
        $status = '';
        $id = '';
        $gauge = '';
        $fibre = '';
        $colours = '';
        $gallery = []; // src => ['src','alt','position','ord']
        $ord = 0;

        foreach ($group as $row) {
            $title = $title !== '' ? $title : self::val($row, 'Title');
            $body = $body !== '' ? $body : self::val($row, 'Body (HTML)', 'Body HTML', 'Body');
            $type = $type !== '' ? $type : self::val($row, 'Type');
            $sku = $sku !== '' ? $sku : self::val($row, 'Variant SKU', 'SKU');
            $price = $price !== '' ? $price : self::val($row, 'Variant Price', 'Price / Australia', 'Price');
            $published = $published !== '' ? $published : self::val($row, 'Published');
            $status = $status !== '' ? $status : self::val($row, 'Status');
            $id = $id !== '' ? $id : self::val($row, 'ID', 'Id', 'id', 'Product ID');
            $gauge = $gauge !== '' ? $gauge : self::val($row, 'Yarn Ply/Weight (product.metafields.custom.gauge)', 'Yarn weight category (product.metafields.shopify.yarn-weight-category)');
            $fibre = $fibre !== '' ? $fibre : self::val($row, 'Fibre (product.metafields.custom.fibre)');
            if ($colours === '') {
                $colours = self::val(
                    $row,
                    'Colours (product.metafields.custom.colours)',
                    'Colors (product.metafields.custom.colors)',
                    'Colour (product.metafields.custom.colour)',
                    'Colours',
                    'Colors',
                    'Colour',
                    'Color',
                    'Tags'
                );
            }

            $q = self::val($row, 'Variant Inventory Qty', 'Variant Inventory Quantity');
            if ($q !== '' && is_numeric($q)) {
                $qty += (int)$q;
            }

            foreach (['Image Src', 'Image src', 'Variant Image'] as $imgKey) {
                $src = self::val($row, $imgKey);
                if ($src === '') {
                    continue;
                }
                $posRaw = self::val($row, 'Image Position');
                $pos = ($posRaw !== '' && is_numeric($posRaw)) ? (int)$posRaw : 1000 + $ord;
                $alt = self::val($row, 'Image Alt Text');
                if (!isset($gallery[$src]) || $pos < $gallery[$src]['position']) {
                    $gallery[$src] = ['src' => $src, 'alt' => $alt, 'position' => $pos, 'ord' => $ord];
                }
                $ord++;
            }
        }

        usort($gallery, static function ($a, $b) {
            if ($a['position'] === $b['position']) {
                return $a['ord'] <=> $b['ord'];
            }
            return $a['position'] <=> $b['position'];
        });
        $images = [];
        foreach ($gallery as $im) {
            $images[] = ['src' => $im['src'], 'alt' => $im['alt']];
        }
        $image = $images[0]['src'] ?? '';

        $category = $type !== '' ? $type : $gauge;
        $description = self::plainText($body);
        if ($fibre !== '') {
            $fibreLine = 'Fibre: ' . preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ', ', $fibre));
            $description = trim($description . ($description !== '' ? "\n\n" : '') . $fibreLine);
        }

        $priceCents = 0;
        if ($price !== '' && is_numeric($price)) {
            $priceCents = (int)round(((float)$price) * 100);
        }

        $isPublic = self::isTruthy($published, true);
        $st = strtolower($status);
        $archived = $st === 'archived' ? 1 : 0;
        $portalStatus = ($st === 'active' || $st === '') ? 'active' : 'inactive';

        return [
            'id'                 => ctype_digit($id) ? (int)$id : 0,
            'shopify_product_id' => $handle,
            'sku'                => $sku,
            'title'              => $title !== '' ? $title : $handle,
            'description'        => $description,
            'category'           => $category,
            'price_cents'        => $priceCents,
            'stock'              => $qty,
            'image_url'          => $image,
            'images_json'        => $images ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            'is_public'          => $isPublic ? 1 : 0,
            'status'             => $portalStatus,
            'archived'           => $archived,
            'colours'            => Catalog::coloursCsv($colours),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    public static function upsert(array $items): array
    {
        $pdo = Database::pdo();
        $created = 0;
        $updated = 0;

        $byId = $pdo->prepare('SELECT id, shopify_product_id, colours FROM products WHERE id = ? LIMIT 1');
        $byShopify = $pdo->prepare('SELECT id, shopify_product_id, colours FROM products WHERE shopify_product_id = ? LIMIT 1');
        $bySku = $pdo->prepare("SELECT id, shopify_product_id, colours FROM products WHERE sku = ? AND sku <> '' LIMIT 1");
        // Stock and catalog fields refresh. Status, vendor, min and goal stay admin-owned.
        $update = $pdo->prepare(
            "UPDATE products SET sku = ?, title = ?, description = ?, category = ?, price_cents = ?,
                    stock = ?, image_url = ?, images_json = ?, archived = ?,
                    shopify_product_id = ?, colours = ?, updated_at = datetime('now')
             WHERE id = ?"
        );
        $insert = $pdo->prepare(
            "INSERT INTO products (sku, title, description, category, price_cents, stock, image_url, images_json,
                                   is_public, status, archived, shopify_product_id, colours)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $existing = null;
                $existingSid = '';
                $csvId = (int)$it['id'];
                if ($csvId > 0) {
                    $byId->execute([$csvId]);
                    $existing = $byId->fetch() ?: null;
                }
                if (!$existing && $csvId > 0) {
                    $byShopify->execute([(string)$csvId]);
                    $existing = $byShopify->fetch() ?: null;
                }
                if (!$existing && $it['shopify_product_id'] !== '') {
                    $byShopify->execute([$it['shopify_product_id']]);
                    $existing = $byShopify->fetch() ?: null;
                }
                if (!$existing && $it['sku'] !== '') {
                    $bySku->execute([$it['sku']]);
                    $existing = $bySku->fetch() ?: null;
                }
                if ($existing) {
                    $existingSid = (string)($existing['shopify_product_id'] ?? '');
                }

                // Prefer a numeric Shopify id (API sync) when the CSV only has a Handle.
                $sid = (string)$it['shopify_product_id'];
                if ($csvId > 0 && strlen((string)$csvId) > 8) {
                    $sid = (string)$csvId;
                } elseif ($existingSid !== '' && ctype_digit($existingSid) && !ctype_digit($sid)) {
                    $sid = $existingSid;
                }

                $colours = ColourDetect::resolveImportColours(
                    $existing ? (string)($existing['colours'] ?? '') : null,
                    (string)($it['colours'] ?? ''),
                    $it
                );
                if ($existing) {
                    $update->execute([
                        $it['sku'], $it['title'], $it['description'], $it['category'],
                        $it['price_cents'], $it['stock'], $it['image_url'], $it['images_json'] ?? '',
                        (int)$it['archived'],
                        $sid, $colours, (int)$existing['id'],
                    ]);
                    $updated++;
                } else {
                    $insert->execute([
                        $it['sku'], $it['title'], $it['description'], $it['category'],
                        $it['price_cents'], $it['stock'], $it['image_url'], $it['images_json'] ?? '',
                        (int)$it['is_public'], $it['status'], (int)$it['archived'],
                        $sid, $colours,
                    ]);
                    $created++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private static function val(array $row, string ...$keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = trim((string)$row[$k]);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private static function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(br|/p|/h[1-6]|/li|/div)\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function isTruthy(string $v, bool $default): bool
    {
        if ($v === '') {
            return $default;
        }
        $v = strtolower($v);
        return in_array($v, ['1', 'true', 'yes', 'on', 'published'], true);
    }

    private static function stripBom(string $csv): string
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            return substr($csv, 3);
        }
        return $csv;
    }
}
