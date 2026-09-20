<?php
declare(strict_types=1);

namespace App;

/**
 * Catalog helpers: product/bundle lookups and image galleries imported from
 * Shopify (CSV or API). The first image is the feature image.
 */
class Catalog
{
    /**
     * Set or replace the CDN `width` query parameter (Shopify-style).
     * $width <= 0 leaves the URL unchanged.
     */
    public static function sizedUrl(string $url, int $width = 0): string
    {
        $url = trim($url);
        if ($url === '' || $width <= 0) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $sep = str_contains($url, '?') ? '&' : '?';
            if (preg_match('/([?&])width=\d+/', $url)) {
                return (string)preg_replace('/([?&])width=\d+/', '${1}width=' . $width, $url, 1);
            }
            return $url . $sep . 'width=' . $width;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['width'] = (string)$width;
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $built = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
        $qs = http_build_query($query);
        if ($qs !== '') {
            $built .= '?' . $qs;
        }
        if (!empty($parts['fragment'])) {
            $built .= '#' . $parts['fragment'];
        }
        return $built;
    }

    public static function images(array $product, int $width = 0): array
    {
        $out = [];
        $seen = [];
        $json = (string)($product['images_json'] ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $src = '';
                    $alt = (string)($product['title'] ?? '');
                    if (is_string($item)) {
                        $src = $item;
                    } elseif (is_array($item)) {
                        $src = (string)($item['src'] ?? $item['url'] ?? '');
                        if (($item['alt'] ?? '') !== '') {
                            $alt = (string)$item['alt'];
                        }
                    }
                    $src = trim($src);
                    if ($src === '' || isset($seen[$src])) {
                        continue;
                    }
                    $seen[$src] = true;
                    $out[] = ['src' => self::sizedUrl($src, $width), 'alt' => $alt];
                }
            }
        }
        $feature = trim((string)($product['image_url'] ?? ''));
        if ($feature !== '' && !isset($seen[$feature])) {
            array_unshift($out, ['src' => self::sizedUrl($feature, $width), 'alt' => (string)($product['title'] ?? '')]);
        }
        return $out;
    }

    /** First image listed (CSV Image Position 1 / Shopify primary image). */
    public static function featureImage(array $product): string
    {
        $imgs = self::images($product);
        return $imgs[0]['src'] ?? '';
    }

    /**
     * Canonical homepage colour chips. Keys are stored in products.colours.
     *
     * @return array<string,array{hex:string,label:string}>
     */
    public static function colourSwatches(): array
    {
        return [
            'red'    => ['hex' => '#E53935', 'label' => 'Red'],
            'coral'  => ['hex' => '#FF6F61', 'label' => 'Coral'],
            'orange' => ['hex' => '#FB8C00', 'label' => 'Orange'],
            'gold'   => ['hex' => '#D4AF37', 'label' => 'Gold'],
            'yellow' => ['hex' => '#FDD835', 'label' => 'Yellow'],
            'green'  => ['hex' => '#43A047', 'label' => 'Green'],
            'teal'   => ['hex' => '#00897B', 'label' => 'Teal'],
            'blue'   => ['hex' => '#1E88E5', 'label' => 'Blue'],
            'navy'   => ['hex' => '#1A237E', 'label' => 'Navy'],
            'purple' => ['hex' => '#8E24AA', 'label' => 'Purple'],
            'pink'   => ['hex' => '#EC407A', 'label' => 'Pink'],
            'brown'  => ['hex' => '#6D4C41', 'label' => 'Brown'],
            'grey'   => ['hex' => '#757575', 'label' => 'Grey'],
            'black'  => ['hex' => '#212121', 'label' => 'Black'],
            'white'  => ['hex' => '#FAFAFA', 'label' => 'White'],
        ];
    }

    /** @return list<string> */
    public static function parseColours(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $parts = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $parts[] = (string)$item;
                } elseif (is_array($item)) {
                    $parts[] = (string)($item['name'] ?? $item['value'] ?? $item['slug'] ?? '');
                }
            }
        } else {
            $parts = preg_split('/[,;|\/]+/', $raw) ?: [];
        }
        $aliases = [
            'gray' => 'grey', 'silver' => 'grey', 'charcoal' => 'grey', 'dove' => 'grey',
            'ocean' => 'blue', 'aqua' => 'teal', 'turquoise' => 'teal',
            'forest' => 'green', 'olive' => 'green',
            'sunset' => 'orange', 'golden' => 'gold',
            'pearl' => 'white', 'cream' => 'white', 'ivory' => 'white',
            'burgundy' => 'red', 'wine' => 'red', 'maroon' => 'red',
            'lavender' => 'purple', 'violet' => 'purple', 'lilac' => 'purple',
            'magenta' => 'pink', 'rose' => 'pink',
            'tan' => 'brown', 'beige' => 'brown', 'chocolate' => 'brown',
            'navy blue' => 'navy', 'sky' => 'blue',
            'variegateds' => 'variegated', 'variegation' => 'variegated',
        ];
        $known = array_merge(array_keys(self::colourSwatches()), ['variegated']);
        $out = [];
        foreach ($parts as $part) {
            $slug = strtolower(trim((string)$part));
            $slug = preg_replace('/[^a-z0-9 -]/', '', $slug) ?? $slug;
            $slug = preg_replace('/\s+/', ' ', $slug) ?? $slug;
            if ($slug === '') {
                continue;
            }
            $slug = $aliases[$slug] ?? $slug;
            if (!in_array($slug, $known, true)) {
                continue;
            }
            $out[$slug] = $slug;
        }
        return array_values($out);
    }

    public static function coloursCsv(string $raw): string
    {
        return implode(',', self::parseColours($raw));
    }

    public static function productHasColour(array $product, string $colour): bool
    {
        return in_array($colour, self::parseColours((string)($product['colours'] ?? '')), true);
    }

    /**
     * Storefront catalog uses Wholesale Status (is_public). Retail Status is
     * the Shopify/CSV live flag and does not hide a product from wholesale.
     * Privileged (admin) listings include every non-archived product.
     */
    public static function productVisibleSql(bool $privileged = false): string
    {
        if ($privileged) {
            return 'archived = 0';
        }
        return 'is_public = 1 AND archived = 0';
    }

    public static function bundleVisibleSql(bool $wholesale = false): string
    {
        // Hidden bundles stay off every storefront (public and wholesale).
        // Historical invoices keep their own line titles and prices.
        return 'is_public = 1 AND archived = 0';
    }

    /** Listing payload for Home, Products, and the wholesale catalog. */
    public static function listingContext(): array
    {
        $privileged = Auth::isAdmin();
        $canOrder = Auth::canOrder();
        return [
            'products'  => self::products($privileged),
            'bundles'   => self::bundles($privileged),
            'wholesale' => $canOrder,
            'showStock' => $canOrder && (Auth::isAdmin() || Settings::wholesaleShowStock()),
            'ignoreMin' => $canOrder && (Auth::isAdmin() || Settings::userIgnoresMin(Auth::dbUser())),
        ];
    }

    /**
     * Permanently remove a bundle. Order line items keep their snapshot
     * title/price; only the live catalog link is cleared.
     */
    public static function deleteBundle(int $id): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE order_items SET bundle_id = NULL WHERE bundle_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM bundle_items WHERE bundle_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM bundles WHERE id = ?')->execute([$id]);
    }

    public static function findProduct(int $id, bool $privileged = false): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM products WHERE id = ? AND ' . self::productVisibleSql($privileged)
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findBundle(int $id, bool $privileged = false): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM bundles WHERE id = ? AND ' . self::bundleVisibleSql($privileged)
        );
        $stmt->execute([$id]);
        $bundle = $stmt->fetch();
        if (!$bundle) {
            return null;
        }
        $bundle['items'] = self::bundleItems((int)$bundle['id'], $privileged);
        if (!$bundle['items']) {
            return null;
        }
            $bundle['min_total_cents'] = array_sum(array_map(
            static fn($i) => Settings::shopperWholesaleCents((int)$i['price_cents']) * self::itemFloorQty($i),
            $bundle['items']
        ));
        $bundle['feature_image'] = self::featureImage($bundle['items'][0]);
        return $bundle;
    }

    /** @return list<array<string,mixed>> */
    public static function bundleItems(int $bundleId, bool $privileged = false): array
    {
        $extra = $privileged
            ? 'AND p.archived = 0'
            : 'AND p.is_public = 1 AND p.archived = 0';
        $stmt = Database::pdo()->prepare(
            "SELECT bi.id AS bundle_item_id, bi.min_qty AS bundle_min_qty, bi.sort_order,
                    p.min_qty, p.id, p.title, p.description,
                    p.category, p.price_cents, p.stock, p.image_url, p.images_json, p.colours
             FROM bundle_items bi
             JOIN products p ON p.id = bi.product_id
             WHERE bi.bundle_id = ? {$extra}
             ORDER BY bi.sort_order, bi.id"
        );
        $stmt->execute([$bundleId]);
        return $stmt->fetchAll();
    }

    /**
     * Persist an admin drag-and-drop order. $itemIds must be the full set of
     * bundle_items.id values for this bundle, in the new display order.
     */
    public static function reorderBundleItems(int $bundleId, array $itemIds): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn($n) => $n > 0
        )));
        if ($bundleId < 1 || !$ids) {
            return false;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM bundle_items WHERE bundle_id = ? ORDER BY sort_order, id');
        $stmt->execute([$bundleId]);
        $existing = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($existing);
        $sorted = $ids;
        sort($sorted);
        if ($existing !== $sorted) {
            return false;
        }
        $upd = $pdo->prepare('UPDATE bundle_items SET sort_order = ? WHERE id = ? AND bundle_id = ?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i, $id, $bundleId]);
        }
        return true;
    }

    /** Grid column count that packs $n thumbs into the most square layout. */
    public static function mosaicCols(int $n): int
    {
        return max(1, (int)ceil(sqrt(max(0, $n))));
    }

    /** Storefront floor for a bundle member (product Min, raised by the bundle min). */
    public static function itemFloorQty(array $item, bool $ignoreMin = false): int
    {
        $bundleMin = (int)($item['bundle_min_qty'] ?? 1);
        return Settings::wholesaleFloorQty($item, $ignoreMin, $bundleMin);
    }

    /** @return list<array<string,mixed>> */
    public static function products(bool $privileged = false): array
    {
        return Database::pdo()->query(
            'SELECT * FROM products WHERE ' . self::productVisibleSql($privileged) . ' ORDER BY title'
        )->fetchAll();
    }

    /** Bundles with member products (and a feature image from the first member). */
    public static function bundles(bool $privileged = false): array
    {
        $rows = Database::pdo()->query(
            'SELECT * FROM bundles WHERE ' . self::bundleVisibleSql($privileged) . ' ORDER BY title'
        )->fetchAll();
        $out = [];
        foreach ($rows as $b) {
            $b['items'] = self::bundleItems((int)$b['id'], $privileged);
            if (!$b['items']) {
                continue;
            }
            $b['min_total_cents'] = array_sum(array_map(
                static fn($i) => Settings::shopperWholesaleCents((int)$i['price_cents']) * self::itemFloorQty($i),
                $b['items']
            ));
            $first = $b['items'][0];
            $own = trim((string)($b['image_url'] ?? ''));
            $b['feature_image'] = $own !== '' ? $own : self::featureImage($first);
            $out[] = $b;
        }
        return $out;
    }
}
