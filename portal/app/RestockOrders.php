<?php
declare(strict_types=1);

namespace App;

/**
 * Per-vendor restock breakdown and schedule rows.
 *
 * A catalog product is flagged when min_qty > 0 and stock <= min_qty.
 * Fulfillable when the matched vendor product has stock > 0.
 * Recommended order qty is max(0, goal_qty - stock), falling back to the shortfall to min.
 */
class RestockOrders
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function lines(): array
    {
        $pdo = Database::pdo();
        $products = $pdo->query(
            "SELECT p.id, p.sku, p.title, p.stock, p.min_qty, p.goal_qty, p.vendor_id, p.status, p.shopify_product_id,
                    v.name AS vendor_name, v.vendor_id AS vendor_code
             FROM products p
             LEFT JOIN vendors v ON v.id = p.vendor_id
             WHERE p.archived = 0 AND p.status = 'active' AND p.min_qty > 0 AND p.stock <= p.min_qty
             ORDER BY v.name COLLATE NOCASE, p.title COLLATE NOCASE"
        )->fetchAll();

        $vpStmt = $pdo->prepare(
            "SELECT * FROM vendor_products
             WHERE archived = 0 AND status = 'active'
               AND (
                    matched_product_id = ?
                    OR (sku != '' AND sku = ?)
                    OR (vendor_product_id != '' AND vendor_product_id = ?)
               )
             ORDER BY CASE WHEN vendor_id = ? THEN 0 ELSE 1 END, stock DESC
             LIMIT 1"
        );

        $out = [];
        foreach ($products as $p) {
            $preferredVendor = (int)($p['vendor_id'] ?? 0);
            $vpStmt->execute([
                (int)$p['id'],
                (string)($p['sku'] ?? ''),
                (string)($p['shopify_product_id'] ?? ''),
                $preferredVendor,
            ]);
            $vp = $vpStmt->fetch() ?: null;
            if ($preferredVendor && $vp && (int)$vp['vendor_id'] !== $preferredVendor) {
                // Prefer the assigned vendor even if another vendor has a match.
                $assigned = $pdo->prepare(
                    'SELECT * FROM vendor_products WHERE vendor_id = ? AND archived = 0 AND status = \'active\'
                     AND (matched_product_id = ? OR (sku != \'\' AND sku = ?)) LIMIT 1'
                );
                $assigned->execute([$preferredVendor, (int)$p['id'], (string)($p['sku'] ?? '')]);
                $vp = $assigned->fetch() ?: $vp;
            }
            $vendorId = $preferredVendor ?: (int)($vp['vendor_id'] ?? 0);
            if ($vendorId < 1) {
                continue;
            }
            if (empty($p['vendor_name'])) {
                $v = $pdo->prepare('SELECT name, vendor_id FROM vendors WHERE id = ?');
                $v->execute([$vendorId]);
                $vr = $v->fetch() ?: [];
                $p['vendor_name'] = $vr['name'] ?? 'Vendor';
                $p['vendor_code'] = $vr['vendor_id'] ?? '';
            }
            $vendorStock = $vp ? (int)$vp['stock'] : 0;
            $goal = max(0, (int)$p['goal_qty']);
            $stock = (int)$p['stock'];
            $min = (int)$p['min_qty'];
            $recommend = $goal > 0 ? max(0, $goal - $stock) : max(0, $min - $stock);
            $fulfillable = $vp !== null && $vendorStock > 0;
            $out[] = [
                'product_id' => (int)$p['id'],
                'sku' => (string)$p['sku'],
                'title' => (string)$p['title'],
                'stock' => $stock,
                'min_qty' => $min,
                'goal_qty' => $goal,
                'recommend_qty' => $recommend,
                'vendor_id' => $vendorId,
                'vendor_name' => (string)$p['vendor_name'],
                'vendor_code' => (string)($p['vendor_code'] ?? ''),
                'vendor_product_id' => $vp ? (int)$vp['id'] : null,
                'vendor_stock' => $vendorStock,
                'vendor_title' => $vp ? (string)$vp['title'] : '',
                'fulfillable' => $fulfillable,
            ];
        }
        return $out;
    }

    /**
     * Vendor groups with fulfillable items on top and unfulfillable below.
     * @return list<array<string,mixed>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::lines() as $line) {
            $vid = (int)$line['vendor_id'];
            if (!isset($groups[$vid])) {
                $groups[$vid] = [
                    'vendor_id' => $vid,
                    'vendor_name' => $line['vendor_name'],
                    'vendor_code' => $line['vendor_code'],
                    'fulfillable' => [],
                    'unfulfillable' => [],
                ];
            }
            if ($line['fulfillable']) {
                $groups[$vid]['fulfillable'][] = $line;
            } else {
                $groups[$vid]['unfulfillable'][] = $line;
            }
        }
        return array_values($groups);
    }

    /** Materialise schedule rows for current restock lines. */
    public static function syncScheduleRows(): void
    {
        $pdo = Database::pdo();
        $find = $pdo->prepare('SELECT id FROM restock_schedules WHERE vendor_id = ? AND product_id = ?');
        $ins = $pdo->prepare(
            'INSERT INTO restock_schedules (vendor_id, product_id, vendor_product_id, title, qty) VALUES (?,?,?,?,?)'
        );
        $upd = $pdo->prepare(
            "UPDATE restock_schedules SET vendor_product_id = ?, title = ?, qty = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $keep = [];
        foreach (self::lines() as $line) {
            $find->execute([(int)$line['vendor_id'], (int)$line['product_id']]);
            $id = $find->fetchColumn();
            if ($id) {
                $upd->execute([
                    $line['vendor_product_id'],
                    $line['title'],
                    (int)$line['recommend_qty'],
                    (int)$id,
                ]);
                $keep[] = (int)$id;
            } else {
                $ins->execute([
                    (int)$line['vendor_id'],
                    (int)$line['product_id'],
                    $line['vendor_product_id'],
                    $line['title'],
                    (int)$line['recommend_qty'],
                ]);
                $keep[] = (int)$pdo->lastInsertId();
            }
        }
        if ($keep) {
            $placeholders = implode(',', array_fill(0, count($keep), '?'));
            $pdo->prepare("DELETE FROM restock_schedules WHERE id NOT IN ($placeholders)")->execute($keep);
        } else {
            $pdo->exec('DELETE FROM restock_schedules');
        }
    }

    /**
     * Gantt-compatible parent rows (vendor id stored as order_id).
     * @return list<array<string,mixed>>
     */
    public static function listGrouped(): array
    {
        self::syncScheduleRows();
        $pdo = Database::pdo();
        $rows = $pdo->query(
            "SELECT rs.*, v.name AS vendor_name, v.vendor_id AS vendor_code,
                    p.stock, p.min_qty, p.goal_qty, p.sku,
                    COALESCE(vp.stock, 0) AS vendor_stock
             FROM restock_schedules rs
             JOIN vendors v ON v.id = rs.vendor_id
             JOIN products p ON p.id = rs.product_id
             LEFT JOIN vendor_products vp ON vp.id = rs.vendor_product_id
             ORDER BY v.name COLLATE NOCASE, rs.title COLLATE NOCASE"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $vendorStock = (int)$r['vendor_stock'];
            $out[] = [
                'id' => (int)$r['id'],
                'order_id' => (int)$r['vendor_id'],
                'product_id' => (int)$r['product_id'],
                'title' => (string)$r['title'],
                'qty' => (int)$r['qty'],
                'status' => $vendorStock > 0 ? 'pending' : 'stalled',
                'notes' => $vendorStock > 0 ? '' : 'Vendor stock is 0',
                'staff_user_id' => null,
                'staff_name' => '',
                'staff_email' => '',
                'client_name' => (string)$r['vendor_name'],
                'client_email' => (string)($r['vendor_code'] ?? ''),
                'starts_at' => $r['starts_at'],
                'ends_at' => $r['ends_at'],
                'trays' => [],
                'vendor_stock' => $vendorStock,
                'catalog_stock' => (int)$r['stock'],
                'min_qty' => (int)$r['min_qty'],
                'goal_qty' => (int)$r['goal_qty'],
                'sku' => (string)$r['sku'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{order_id:int,client_name:string,client_email:string}>
     */
    public static function provisioningOrders(): array
    {
        $seen = [];
        $out = [];
        foreach (self::listGrouped() as $row) {
            $vid = (int)$row['order_id'];
            if (isset($seen[$vid])) {
                continue;
            }
            $seen[$vid] = true;
            $out[] = [
                'order_id' => $vid,
                'client_name' => (string)$row['client_name'],
                'client_email' => (string)$row['client_email'],
            ];
        }
        return $out;
    }

    /**
     * Portal restock reports: active catalog items whose on-hand stock is below Goal.
     * Grouped by assigned vendor. ProductID is the Shopify product id when set, else catalog id.
     *
     * @return list<array{vendor_id:int,vendor_name:string,vendor_code:string,lines:list<array<string,mixed>>}>
     */
    public static function goalReports(): array
    {
        $pdo = Database::pdo();
        $products = $pdo->query(
            "SELECT p.id, p.sku, p.title, p.stock, p.min_qty, p.goal_qty, p.vendor_id, p.shopify_product_id, p.source_id,
                    COALESCE(NULLIF(s.vendor_name, ''), NULLIF(v.name, ''), 'Unassigned') AS vendor_name,
                    COALESCE(NULLIF(s.collection_name, ''), NULLIF(s.collection_id, ''), '') AS collection_name,
                    v.vendor_id AS vendor_code
             FROM products p
             LEFT JOIN sources s ON s.id = p.source_id
             LEFT JOIN vendors v ON v.id = p.vendor_id
             WHERE p.archived = 0 AND p.status = 'active' AND p.goal_qty > p.stock
             ORDER BY vendor_name COLLATE NOCASE, collection_name COLLATE NOCASE, p.title COLLATE NOCASE"
        )->fetchAll();

        $groups = [];
        foreach ($products as $p) {
            $vendorName = trim((string)($p['vendor_name'] ?? ''));
            if ($vendorName === '') {
                $vendorName = 'Unassigned';
            }
            $collection = trim((string)($p['collection_name'] ?? ''));
            $shopifyId = trim((string)($p['shopify_product_id'] ?? ''));
            $productId = $shopifyId !== '' ? $shopifyId : (string)(int)$p['id'];
            $stock = (int)$p['stock'];
            $goal = (int)$p['goal_qty'];
            $min = (int)$p['min_qty'];
            $need = max(0, $goal - $stock);
            $key = strtolower($vendorName);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vendor_id' => (int)($p['vendor_id'] ?? 0),
                    'vendor_name' => $vendorName,
                    'vendor_code' => (string)($p['vendor_code'] ?? ''),
                    'collections' => [],
                    'label' => $vendorName,
                    'lines' => [],
                ];
            }
            if ($collection !== '' && !in_array($collection, $groups[$key]['collections'], true)) {
                $groups[$key]['collections'][] = $collection;
            }
            $groups[$key]['lines'][] = [
                'sku' => (string)($p['sku'] ?? ''),
                'product_id' => $productId,
                'catalog_id' => (int)$p['id'],
                'title' => (string)$p['title'],
                'stock' => $stock,
                'min_qty' => $min,
                'goal_qty' => $goal,
                'need_qty' => $need,
                'collection_name' => $collection,
            ];
        }
        foreach ($groups as &$g) {
            natcasesort($g['collections']);
            $g['collections'] = array_values($g['collections']);
            $g['label'] = Sources::displayLabel((string)$g['vendor_name'], $g['collections']);
        }
        unset($g);
        return array_values($groups);
    }

    /**
     * Filter a vendor group and its lines by keyword (SKU / id / title).
     *
     * @return array<string,mixed>|null
     */
    public static function findReport(string $vendor, string $keyword = ''): ?array
    {
        $want = strtolower(trim($vendor));
        $q = strtolower(trim($keyword));
        foreach (self::goalReports() as $g) {
            $name = strtolower((string)$g['vendor_name']);
            $label = strtolower((string)$g['label']);
            if ($want !== '' && $name !== $want && !str_contains($name, $want) && !str_contains($label, $want)) {
                continue;
            }
            if ($q !== '') {
                $g['lines'] = array_values(array_filter($g['lines'], static function (array $line) use ($q): bool {
                    $hay = strtolower(trim(($line['sku'] ?? '') . ' ' . ($line['product_id'] ?? '') . ' ' . ($line['title'] ?? '')));
                    return str_contains($hay, $q);
                }));
            }
            if ($g['lines'] === []) {
                continue;
            }
            return $g;
        }
        return null;
    }

    /** SKU / product ID shown on copy and PDF when the catalog value is missing. */
    public static function displayCode(mixed $value): string
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '—') {
            return 'unknown';
        }
        return $v;
    }

    /** Pastel red at 0 inventory. */
    public const URGENCY_RED = '#f7c4c0';
    /** Pastel orange at 50% of Min. */
    public const URGENCY_ORANGE = '#fcd4a8';
    /** Pastel yellow at Min (and above). */
    public const URGENCY_YELLOW = '#f6eaa8';

    /**
     * Continuous pastel shade for inventory as a fraction of Min:
     * 0 = red, 50% = orange, Min = yellow. Values in between are mixed.
     * Stock above Min stays yellow. A Min of 0 uses red only when stock is 0.
     */
    public static function urgencyColor(int $stock, int $min): string
    {
        $red = self::hexToRgb(self::URGENCY_RED);
        $orange = self::hexToRgb(self::URGENCY_ORANGE);
        $yellow = self::hexToRgb(self::URGENCY_YELLOW);
        if ($stock <= 0) {
            return self::rgbToHex($red);
        }
        if ($min <= 0) {
            return self::rgbToHex($yellow);
        }
        $t = min(1.0, $stock / $min);
        if ($t <= 0.5) {
            return self::rgbToHex(self::lerpRgb($red, $orange, $t / 0.5));
        }
        return self::rgbToHex(self::lerpRgb($orange, $yellow, ($t - 0.5) / 0.5));
    }

    /** Coarse stop used by tests and the legend: red / orange / yellow. */
    public static function urgencyLevel(int $stock, int $min): string
    {
        if ($stock <= 0) {
            return 'red';
        }
        if ($min <= 0) {
            return 'yellow';
        }
        $ratio = $stock / $min;
        if ($ratio < 0.5) {
            return 'red';
        }
        if ($ratio < 1.0) {
            return 'orange';
        }
        return 'yellow';
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $from
     * @param array{0:int,1:int,2:int} $to
     * @return array{0:int,1:int,2:int}
     */
    private static function lerpRgb(array $from, array $to, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int)round($from[0] + ($to[0] - $from[0]) * $t),
            (int)round($from[1] + ($to[1] - $from[1]) * $t),
            (int)round($from[2] + ($to[2] - $from[2]) * $t),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function rgbToHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    public static function urgencyColorsEnabled(): bool
    {
        return Settings::reportUrgencyColors();
    }

    /**
     * How many lines in a vendor report are at or below Min.
     *
     * @param array<string,mixed> $group
     */
    public static function belowMinCount(array $group): int
    {
        $n = 0;
        foreach ($group['lines'] ?? [] as $l) {
            $min = (int)($l['min_qty'] ?? 0);
            $stock = (int)($l['stock'] ?? 0);
            if ($min > 0 && $stock <= $min) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Vendor reports with the most products at or below Min, highest first.
     *
     * @param list<array<string,mixed>> $reports
     * @return list<array<string,mixed>>
     */
    public static function attentionReports(array $reports, int $limit = 3): array
    {
        $scored = [];
        foreach ($reports as $g) {
            $g['below_min'] = self::belowMinCount($g);
            if ($g['below_min'] > 0) {
                $scored[] = $g;
            }
        }
        usort($scored, static function (array $a, array $b): int {
            $d = ((int)$b['below_min']) <=> ((int)$a['below_min']);
            if ($d !== 0) {
                return $d;
            }
            return strcasecmp((string)($a['vendor_name'] ?? ''), (string)($b['vendor_name'] ?? ''));
        });
        return array_slice($scored, 0, max(0, $limit));
    }

    /**
     * Human-readable restock request suitable for pasting into a vendor email.
     *
     * @param array<string,mixed> $group
     * @param list<array<string,mixed>>|null $lines
     */
    public static function reportText(array $group, ?array $lines = null): string
    {
        $lines = $lines ?? ($group['lines'] ?? []);
        $vendor = (string)($group['vendor_name'] ?? 'Vendor');
        $code = trim((string)($group['vendor_code'] ?? ''));
        $totalQty = 0;
        foreach ($lines as $l) {
            $totalQty += (int)($l['need_qty'] ?? 0);
        }
        $label = trim((string)($group['label'] ?? $vendor));
        $n = count($lines);
        $out = [];
        $out[] = 'Restock Request Form';
        $out[] = $label;
        if ($code !== '') {
            $out[] = 'Vendor code: ' . $code;
        }
        $out[] = 'Date: ' . date('j F Y');
        $out[] = sprintf(
            '%d item%s · %d unit%s to order',
            $n,
            $n === 1 ? '' : 's',
            $totalQty,
            $totalQty === 1 ? '' : 's'
        );
        $out[] = str_repeat('=', 48);
        $out[] = '';
        $out[] = 'Hello,';
        $out[] = '';
        $out[] = 'Please supply the following so we can bring on-hand stock up to our goal levels:';
        $out[] = '';
        $i = 1;
        foreach ($lines as $l) {
            $out[] = $i . '. ' . (string)($l['title'] ?? '');
            $out[] = '   SKU: ' . self::displayCode($l['sku'] ?? '');
            $out[] = '   Product ID: ' . self::displayCode($l['product_id'] ?? '');
            $out[] = '   Current inventory: ' . (int)($l['stock'] ?? 0);
            $out[] = '   Order quantity: ' . (int)($l['need_qty'] ?? 0)
                . '  (goal ' . (int)($l['goal_qty'] ?? 0) . ' − current ' . (int)($l['stock'] ?? 0) . ')';
            $out[] = '   Total: ' . ((int)($l['stock'] ?? 0) + (int)($l['need_qty'] ?? 0));
            $out[] = '';
            $i++;
        }
        $out[] = 'Thank you.';
        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * Professionally formatted Restock Request Form PDF for a vendor group.
     *
     * @param array<string,mixed> $group
     * @param list<array<string,mixed>>|null $lines
     */
    public static function reportPdf(array $group, ?array $lines = null): string
    {
        $lines = $lines ?? ($group['lines'] ?? []);
        $company = Settings::get('company_name', 'Restock portal');
        $label = trim((string)($group['label'] ?? $group['vendor_name'] ?? 'Vendor'));
        $rows = [];
        foreach ($lines as $l) {
            $inv = (int)($l['stock'] ?? 0);
            $need = (int)($l['need_qty'] ?? 0);
            $rows[] = [
                self::displayCode($l['sku'] ?? ''),
                self::displayCode($l['product_id'] ?? ''),
                (string)($l['title'] ?? ''),
                (string)$inv,
                (string)$need,
                (string)($inv + $need),
            ];
        }
        return Pdf::build([
            'title' => 'Restock Request Form',
            'subtitle' => $label,
            'meta' => [
                'Prepared by ' . $company,
                'Date: ' . date('j F Y'),
                count($lines) . ' item' . (count($lines) === 1 ? '' : 's') . ' below goal',
            ],
            'headers' => ['SKU', 'Product ID', 'Product Name', 'Inv', 'Order Qty', 'Total'],
            'rows' => $rows,
            'footer' => 'Please supply the listed quantities so on-hand stock can return to goal levels. Thank you.',
        ]);
    }

    public static function reportFilename(array $group): string
    {
        $label = (string)($group['label'] ?? $group['vendor_name'] ?? 'vendor');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? 'vendor');
        $slug = trim($slug, '-');
        return 'restock-request-' . ($slug !== '' ? $slug : 'vendor') . '.pdf';
    }

    public static function staffRates(): array
    {
        return [];
    }

    public static function scheduleConflicts(): array
    {
        return [];
    }

    public static function setSchedule(int $id, string $start, string $end): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return false;
        }
        if ($end < $start) {
            $end = $start;
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE restock_schedules SET starts_at = ?, ends_at = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$start, $end, $id]);
        return $stmt->rowCount() > 0 || self::exists($id);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    public static function setScheduleBatch(array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            $id = (int)($it['id'] ?? 0);
            $start = (string)($it['starts_at'] ?? '');
            $end = (string)($it['ends_at'] ?? $start);
            if ($id > 0 && self::setSchedule($id, $start, $end)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param list<int> $vendorIds
     * @param list<int> $unitIds
     * @return array{ok:bool,updated:int,conflicts:list<mixed>}
     */
    public static function autoSchedule(array $vendorIds, array $unitIds): array
    {
        $from = date('Y-m-d');
        $rows = self::listGrouped();
        $byVendor = [];
        foreach ($rows as $r) {
            if ($unitIds && !in_array((int)$r['id'], $unitIds, true)) {
                continue;
            }
            if ($vendorIds && !in_array((int)$r['order_id'], $vendorIds, true)) {
                continue;
            }
            $byVendor[(int)$r['order_id']][] = $r;
        }
        $n = 0;
        foreach ($byVendor as $items) {
            $day = $from;
            foreach ($items as $item) {
                if (self::setSchedule((int)$item['id'], $day, $day)) {
                    $n++;
                }
                $day = date('Y-m-d', strtotime($day . ' +1 day') ?: time());
            }
        }
        return ['ok' => true, 'updated' => $n, 'conflicts' => []];
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    public static function restoreDates(array $items): int
    {
        return self::setScheduleBatch($items);
    }

    private static function exists(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM restock_schedules WHERE id = ?');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array{below_min:int,fulfillable:int,unfulfillable:int,vendors:int} */
    public static function stats(): array
    {
        $groups = self::grouped();
        $fulfillable = 0;
        $unfulfillable = 0;
        foreach ($groups as $g) {
            $fulfillable += count($g['fulfillable']);
            $unfulfillable += count($g['unfulfillable']);
        }
        return [
            'below_min' => $fulfillable + $unfulfillable,
            'fulfillable' => $fulfillable,
            'unfulfillable' => $unfulfillable,
            'vendors' => count($groups),
        ];
    }
}
