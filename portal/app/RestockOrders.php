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
            "SELECT p.id, p.sku, p.title, p.stock, p.goal_qty, p.vendor_id, p.shopify_product_id,
                    v.name AS vendor_name, v.vendor_id AS vendor_code
             FROM products p
             LEFT JOIN vendors v ON v.id = p.vendor_id
             WHERE p.archived = 0 AND p.status = 'active' AND p.goal_qty > p.stock
             ORDER BY COALESCE(NULLIF(v.name, ''), 'Unassigned') COLLATE NOCASE, p.title COLLATE NOCASE"
        )->fetchAll();

        $groups = [];
        foreach ($products as $p) {
            $vendorId = (int)($p['vendor_id'] ?? 0);
            $vendorName = trim((string)($p['vendor_name'] ?? ''));
            if ($vendorName === '') {
                $vendorName = 'Unassigned';
            }
            $shopifyId = trim((string)($p['shopify_product_id'] ?? ''));
            $productId = $shopifyId !== '' ? $shopifyId : (string)(int)$p['id'];
            $stock = (int)$p['stock'];
            $goal = (int)$p['goal_qty'];
            $need = max(0, $goal - $stock);
            $key = $vendorId > 0 ? $vendorId : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'vendor_code' => (string)($p['vendor_code'] ?? ''),
                    'lines' => [],
                ];
            }
            $groups[$key]['lines'][] = [
                'sku' => (string)($p['sku'] ?? ''),
                'product_id' => $productId,
                'catalog_id' => (int)$p['id'],
                'title' => (string)$p['title'],
                'stock' => $stock,
                'goal_qty' => $goal,
                'need_qty' => $need,
            ];
        }
        return array_values($groups);
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
        $header = 'Restock request — ' . $vendor;
        if ($code !== '') {
            $header .= ' (' . $code . ')';
        }
        $n = count($lines);
        $out = [];
        $out[] = $header;
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
            $sku = trim((string)($l['sku'] ?? ''));
            $out[] = $i . '. ' . (string)($l['title'] ?? '');
            $out[] = '   SKU: ' . ($sku !== '' ? $sku : '—');
            $out[] = '   Product ID: ' . (string)($l['product_id'] ?? '');
            $out[] = '   Quantity to order: ' . (int)($l['need_qty'] ?? 0)
                . '  (goal ' . (int)($l['goal_qty'] ?? 0) . ' − current ' . (int)($l['stock'] ?? 0) . ')';
            $out[] = '';
            $i++;
        }
        $out[] = 'Thank you.';
        return rtrim(implode("\n", $out)) . "\n";
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
