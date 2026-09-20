<?php
declare(strict_types=1);

namespace App;

/**
 * Work orders created when a client order enters "provisioning".
 * One parent row per product type, plus Tray N sub-tasks when qty exceeds SPT.
 */
class WorkOrders
{
    public const STATUSES = ['pending', 'stalled', 'complete', 'filled_from_stock'];
    public const DONE_STATUSES = ['complete', 'filled_from_stock'];

    public static function isDone(string $status): bool
    {
        return in_array($status, self::DONE_STATUSES, true);
    }

    public static function onStatusChange(int $orderId, string $oldStatus, string $newStatus): void
    {
        if ($newStatus === 'provisioning') {
            self::syncFromOrder($orderId, $oldStatus !== 'provisioning');
        }
    }

    /**
     * Split an ordered qty into tray sizes of at most $spt.
     * Returns [] when the order fits on a single tray (qty <= spt).
     *
     * @return list<int>
     */
    public static function trayPlan(int $qty, int $spt): array
    {
        $qty = max(1, $qty);
        $spt = max(1, $spt);
        if ($qty <= $spt) {
            return [];
        }
        $trays = [];
        $left = $qty;
        while ($left > 0) {
            $take = min($spt, $left);
            $trays[] = $take;
            $left -= $take;
        }
        return $trays;
    }

    /**
     * Create missing product-type rows for a provisioning order.
     * When $reset is true (order just entered provisioning again), item
     * statuses return to pending so the work can be redone.
     */
    public static function syncFromOrder(int $orderId, bool $reset = false): void
    {
        $pdo = Database::pdo();
        $order = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        if (!$order->fetch()) {
            return;
        }

        $items = $pdo->prepare('SELECT product_id, title, qty FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $groups = [];
        foreach ($items->fetchAll() as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $title = (string)$it['title'];
            if ($pid > 0) {
                $pt = $pdo->prepare('SELECT title FROM products WHERE id = ?');
                $pt->execute([$pid]);
                $found = $pt->fetchColumn();
                if ($found) {
                    $title = (string)$found;
                }
            }
            $key = $pid > 0 ? 'p:' . $pid : 't:' . md5($title);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'product_id' => $pid > 0 ? $pid : null,
                    'title'      => $title,
                    'qty'        => 0,
                ];
            }
            $groups[$key]['qty'] += max(1, (int)$it['qty']);
        }

        if ($reset) {
            $pdo->prepare("UPDATE work_orders SET status = 'pending', notified_at = NULL, updated_at = datetime('now') WHERE order_id = ?")
                ->execute([$orderId]);
        }

        $existing = $pdo->prepare(
            'SELECT id, product_id, title, qty FROM work_orders WHERE order_id = ? AND (parent_id IS NULL OR parent_id = 0)'
        );
        $existing->execute([$orderId]);
        $have = [];
        foreach ($existing->fetchAll() as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $have[$pid > 0 ? 'p:' . $pid : 't:' . md5((string)$row['title'])] = $row;
        }

        $today = date('Y-m-d');
        $ins = $pdo->prepare(
            "INSERT INTO work_orders (order_id, product_id, title, qty, status, starts_at, ends_at)
             VALUES (?,?,?,?,'pending',?,?)"
        );
        $updQty = $pdo->prepare("UPDATE work_orders SET qty = ?, updated_at = datetime('now') WHERE id = ?");
        $updQtyChanged = $pdo->prepare(
            "UPDATE work_orders SET qty = ?, qty_was = ?, qty_changed_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
        );
        $seen = [];
        foreach ($groups as $key => $g) {
            if (isset($have[$key])) {
                $wid = (int)$have[$key]['id'];
                $oldQty = (int)$have[$key]['qty'];
                if ($oldQty !== (int)$g['qty']) {
                    $updQtyChanged->execute([(int)$g['qty'], $oldQty, $wid]);
                } else {
                    $updQty->execute([(int)$g['qty'], $wid]);
                }
                $seen[$wid] = true;
                self::syncTrays($wid);
            } else {
                $ins->execute([$orderId, $g['product_id'], $g['title'], $g['qty'], $today, $today]);
                $newId = (int)$pdo->lastInsertId();
                self::syncTrays($newId);
            }
        }
        foreach ($have as $row) {
            $wid = (int)$row['id'];
            if (!isset($seen[$wid])) {
                $pdo->prepare('DELETE FROM work_orders WHERE parent_id = ?')->execute([$wid]);
                $pdo->prepare('DELETE FROM work_orders WHERE id = ?')->execute([$wid]);
            }
        }
        self::packOrderWork($orderId);
    }

    /** Create missing Tray N rows when the parent qty exceeds the product SPT. */
    public static function syncTrays(int $parentId): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM work_orders WHERE id = ? AND (parent_id IS NULL OR parent_id = 0)');
        $st->execute([$parentId]);
        $parent = $st->fetch();
        if (!$parent) {
            return;
        }
        $product = null;
        $pid = (int)($parent['product_id'] ?? 0);
        if ($pid > 0) {
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([$pid]);
            $product = $p->fetch() ?: null;
        }
        $spt = Settings::sptForProduct($product);
        $plan = self::trayPlan((int)$parent['qty'], $spt);
        if (!$plan) {
            return;
        }

        $have = $pdo->prepare('SELECT id, tray_no, qty FROM work_orders WHERE parent_id = ? ORDER BY tray_no, id');
        $have->execute([$parentId]);
        $byNo = [];
        foreach ($have->fetchAll() as $row) {
            $byNo[(int)$row['tray_no']] = $row;
        }

        $today = $parent['starts_at'] ?: date('Y-m-d');
        $ins = $pdo->prepare(
            "INSERT INTO work_orders (order_id, product_id, title, qty, staff_user_id, status, parent_id, tray_no, starts_at, ends_at)
             VALUES (?,?,?,?,?,'pending',?,?,?,?)"
        );
        foreach ($plan as $i => $trayQty) {
            $no = $i + 1;
            if (isset($byNo[$no])) {
                continue;
            }
            $ins->execute([
                (int)$parent['order_id'],
                $parent['product_id'] !== null ? (int)$parent['product_id'] : null,
                'Tray ' . $no,
                $trayQty,
                $parent['staff_user_id'] !== null ? (int)$parent['staff_user_id'] : null,
                $parentId,
                $no,
                $today,
                $today,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(): array
    {
        return Database::pdo()->query(
            "SELECT wo.*, o.status AS order_status, o.user_id,
                    TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS client_name,
                    u.email AS client_email,
                    TRIM(COALESCE(s.first_name,'') || ' ' || COALESCE(s.last_name,'')) AS staff_name,
                    s.email AS staff_email,
                    s.tray_rate AS staff_tray_rate,
                    p.spt AS product_spt,
                    p.warehouse_stock AS product_ws
             FROM work_orders wo
             JOIN orders o ON o.id = wo.order_id
             JOIN users u ON u.id = o.user_id
             LEFT JOIN users s ON s.id = wo.staff_user_id
             LEFT JOIN products p ON p.id = wo.product_id
             WHERE o.status = 'provisioning'
             ORDER BY client_name COLLATE NOCASE, o.id, COALESCE(wo.parent_id, wo.id), wo.parent_id IS NOT NULL, wo.tray_no, wo.title COLLATE NOCASE"
        )->fetchAll();
    }

    /** Parent product-type rows only (tray sub-tasks are nested via listGrouped). */
    public static function list(): array
    {
        return array_values(array_filter(
            self::listAll(),
            static fn(array $w): bool => (int)($w['parent_id'] ?? 0) <= 0
        ));
    }

    /**
     * Parent work-order rows (no trays) plus nested tray children.
     *
     * @return list<array<string,mixed>>
     */
    public static function listGrouped(): array
    {
        $rows = self::listAll();
        $byId = [];
        foreach ($rows as $w) {
            $w['trays'] = [];
            $byId[(int)$w['id']] = $w;
        }
        foreach ($rows as $w) {
            $pid = (int)($w['parent_id'] ?? 0);
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['trays'][] = $w;
            }
        }
        $out = [];
        foreach ($byId as $w) {
            if ((int)($w['parent_id'] ?? 0) > 0) {
                continue;
            }
            $out[] = self::decorateParent($w);
        }
        return $out;
    }

    /**
     * Coverage, completion, and default-collapse flags for a parent row.
     *
     * @param array<string,mixed> $w
     * @return array<string,mixed>
     */
    public static function decorateParent(array $w): array
    {
        $trays = $w['trays'] ?? [];
        $ordered = max(0, (int)($w['qty'] ?? 0));
        $covered = $ordered;
        $allDone = self::isDone((string)($w['status'] ?? ''));
        if ($trays) {
            $covered = 0;
            $allDone = true;
            foreach ($trays as $t) {
                $covered += max(0, (int)($t['qty'] ?? 0));
                if (!self::isDone((string)($t['status'] ?? ''))) {
                    $allDone = false;
                }
            }
        }
        $w['ordered_qty'] = $ordered;
        $w['covered_qty'] = $covered;
        $w['qty_shortfall'] = $covered < $ordered;
        $w['all_subtasks_done'] = $allDone;
        $w['qty_changed'] = trim((string)($w['qty_changed_at'] ?? '')) !== '';
        $w['default_collapsed'] = $trays !== [] && $allDone;
        return $w;
    }

    /** Orders in provisioning whose work-order items are all complete or filled from stock. */
    public static function readyForApproval(): array
    {
        $done = "'" . implode("','", self::DONE_STATUSES) . "'";
        return Database::pdo()->query(
            "SELECT o.id AS order_id,
                    TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS client_name,
                    u.email AS client_email
             FROM orders o
             JOIN users u ON u.id = o.user_id
             WHERE o.status = 'provisioning'
               AND EXISTS (SELECT 1 FROM work_orders w WHERE w.order_id = o.id)
               AND NOT EXISTS (SELECT 1 FROM work_orders w WHERE w.order_id = o.id AND w.status NOT IN ({$done}))
             ORDER BY o.id"
        )->fetchAll();
    }

    /**
     * Provisioning orders that still have at least one parent work-order item
     * with no staff member assigned.
     *
     * @return list<array<string,mixed>>
     */
    public static function unassignedProvisioning(): array
    {
        return Database::pdo()->query(
            "SELECT o.id AS order_id,
                    TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS client_name,
                    u.email AS client_email,
                    COUNT(*) AS unassigned_items
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN work_orders wo ON wo.order_id = o.id
             WHERE o.status = 'provisioning'
               AND (wo.parent_id IS NULL OR wo.parent_id = 0)
               AND (wo.staff_user_id IS NULL OR wo.staff_user_id = 0)
             GROUP BY o.id
             ORDER BY o.id"
        )->fetchAll();
    }

    /**
     * Open stalled work-order items on provisioning orders.
     *
     * @return list<array<string,mixed>>
     */
    public static function stalledItems(): array
    {
        return Database::pdo()->query(
            "SELECT wo.id, wo.order_id, wo.title, wo.qty, wo.notes,
                    TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS client_name,
                    u.email AS client_email,
                    TRIM(COALESCE(s.first_name,'') || ' ' || COALESCE(s.last_name,'')) AS staff_name,
                    s.email AS staff_email
             FROM work_orders wo
             JOIN orders o ON o.id = wo.order_id
             JOIN users u ON u.id = o.user_id
             LEFT JOIN users s ON s.id = wo.staff_user_id
             WHERE o.status = 'provisioning' AND wo.status = 'stalled'
             ORDER BY o.id, wo.title COLLATE NOCASE"
        )->fetchAll();
    }

    /** @return list<array{order_id:int,client_name:string,client_email:string}> */
    public static function provisioningOrders(): array
    {
        return Database::pdo()->query(
            "SELECT o.id AS order_id,
                    TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS client_name,
                    u.email AS client_email
             FROM orders o
             JOIN users u ON u.id = o.user_id
             WHERE o.status = 'provisioning'
               AND EXISTS (SELECT 1 FROM work_orders w WHERE w.order_id = o.id AND (w.parent_id IS NULL OR w.parent_id = 0))
             ORDER BY o.id"
        )->fetchAll();
    }

    public static function setItemStatus(int $id, string $status, ?string $note = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $pdo = Database::pdo();
        $prev = $pdo->prepare('SELECT status, notes, title, order_id FROM work_orders WHERE id = ?');
        $prev->execute([$id]);
        $row = $prev->fetch();
        if (!$row) {
            return;
        }
        $oldStatus = (string)$row['status'];
        $notes = $note !== null ? trim($note) : (string)($row['notes'] ?? '');
        if ($status === 'stalled' && $notes === '') {
            return;
        }
        $pdo->prepare("UPDATE work_orders SET status = ?, notes = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$status, $notes === '' ? null : $notes, $id]);
        if ($status === 'stalled' && $oldStatus !== 'stalled') {
            self::notifyStalled($id, $row, $notes);
        }
        $oid = (int)$row['order_id'];
        if ($oid) {
            self::maybeNotify($oid);
        }
    }

    public static function setItemNote(int $id, string $note): void
    {
        Database::pdo()->prepare("UPDATE work_orders SET notes = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([trim($note) !== '' ? trim($note) : null, $id]);
    }

    /** Staff may override tray quantities (parent qty stays the client-ordered amount). */
    public static function setItemQty(int $id, int $qty): bool
    {
        $qty = max(1, $qty);
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, parent_id FROM work_orders WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row || (int)($row['parent_id'] ?? 0) <= 0) {
            return false;
        }
        $pdo->prepare("UPDATE work_orders SET qty = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$qty, $id]);
        return true;
    }

    public static function setSchedule(int $id, string $startsAt, string $endsAt): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAt)) {
            return false;
        }
        if ($endsAt < $startsAt) {
            $tmp = $startsAt;
            $startsAt = $endsAt;
            $endsAt = $tmp;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM work_orders WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            return false;
        }
        $pdo->prepare("UPDATE work_orders SET starts_at = ?, ends_at = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$startsAt, $endsAt, $id]);
        return true;
    }

    /**
     * Persist many schedule rows in one go (Gantt multi-select / order-band moves).
     *
     * @param list<array{id?:mixed,starts_at?:mixed,ends_at?:mixed}> $items
     */
    public static function setScheduleBatch(array $items): int
    {
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int)($it['id'] ?? 0);
            if ($id > 0 && self::setSchedule($id, (string)($it['starts_at'] ?? ''), (string)($it['ends_at'] ?? ''))) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $row */
    private static function notifyStalled(int $id, array $row, string $notes): void
    {
        $oid = (int)$row['order_id'];
        $title = (string)$row['title'];
        $client = Database::pdo()->prepare(
            "SELECT TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS name, u.email
             FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?"
        );
        $client->execute([$oid]);
        $c = $client->fetch() ?: ['name' => '', 'email' => ''];
        $label = trim((string)($c['name'] ?? '')) ?: (string)($c['email'] ?? '');
        $who = Auth::user();
        $staff = $who ? (string)($who['display_name'] ?? $who['email'] ?? 'Staff') : 'Staff';
        Alerts::log(
            Auth::id(),
            'work_order_stalled',
            'Order #' . $oid . ' for ' . $label . ' — "' . $title . '" stalled by ' . $staff . ': ' . $notes
        );
    }

    public static function assignStaff(int $id, ?int $staffUserId): void
    {
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE work_orders SET staff_user_id = ?, updated_at = datetime('now') WHERE id = ? OR parent_id = ?")
            ->execute([$staffUserId ?: null, $id, $id]);
        if ($staffUserId) {
            $st = $pdo->prepare('SELECT order_id FROM work_orders WHERE id = ?');
            $st->execute([$id]);
            $oid = (int)$st->fetchColumn();
            if ($oid > 0) {
                self::placeUnits($staffUserId, self::unitsForOrder($oid));
            }
        }
    }

    public static function assignStaffToOrder(int $orderId, ?int $staffUserId): void
    {
        Database::pdo()->prepare("UPDATE work_orders SET staff_user_id = ?, updated_at = datetime('now') WHERE order_id = ?")
            ->execute([$staffUserId ?: null, $orderId]);
        if ($staffUserId) {
            self::placeUnits($staffUserId, self::unitsForOrder($orderId));
        } else {
            self::packOrderWork($orderId);
        }
    }

    public static function trayRateFor(?int $staffUserId): int
    {
        if (!$staffUserId) {
            return 10;
        }
        $st = Database::pdo()->prepare('SELECT tray_rate FROM users WHERE id = ?');
        $st->execute([$staffUserId]);
        $n = (int)$st->fetchColumn();
        return max(1, $n > 0 ? $n : 10);
    }

    /**
     * Work units that consume tray-rate capacity: tray rows, or the parent
     * when it has no trays.
     *
     * @return list<array<string,mixed>>
     */
    public static function unitsForOrder(int $orderId): array
    {
        $rows = Database::pdo()->prepare(
            'SELECT * FROM work_orders WHERE order_id = ? ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, tray_no, id'
        );
        $rows->execute([$orderId]);
        return self::unitsFromRows($rows->fetchAll());
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function unitsFromRows(array $rows): array
    {
        $hasTrays = [];
        foreach ($rows as $row) {
            $pid = (int)($row['parent_id'] ?? 0);
            if ($pid > 0) {
                $hasTrays[$pid] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if (self::isDone((string)($row['status'] ?? ''))) {
                continue;
            }
            $pid = (int)($row['parent_id'] ?? 0);
            if ($pid > 0) {
                $out[] = $row;
                continue;
            }
            if (empty($hasTrays[(int)$row['id']])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Default-pack an order's trays into 1-day boxes, overflowing to the next
     * day once the staff (or default 10) tray rate is filled. Assigned staff
     * also keep capacity already booked on other orders.
     */
    public static function packOrderWork(int $orderId): void
    {
        $units = self::unitsForOrder($orderId);
        if (!$units) {
            return;
        }
        $byStaff = [];
        foreach ($units as $u) {
            $sid = (int)($u['staff_user_id'] ?? 0);
            $byStaff[$sid][] = $u;
        }
        foreach ($byStaff as $sid => $group) {
            self::placeUnits($sid > 0 ? $sid : null, $group);
        }
    }

    /**
     * Place units as 1-day boxes: keep a work-order's trays on one day when
     * the tray rate allows, otherwise overflow to the next day. Matching
     * products across orders prefer the same day. Days fill to the rate so
     * the staff member stays busy in the shortest sequential window.
     *
     * @param list<array<string,mixed>> $units
     */
    public static function placeUnits(?int $staffUserId, array $units): void
    {
        foreach (self::planUnits($staffUserId, $units) as $it) {
            self::setSchedule((int)$it['id'], $it['starts_at'], $it['ends_at']);
        }
        $parents = [];
        foreach ($units as $u) {
            $pid = (int)($u['parent_id'] ?? 0);
            if ($pid > 0) {
                $parents[$pid] = true;
            }
        }
        foreach (array_keys($parents) as $pid) {
            self::syncParentSpan($pid);
        }
    }

    public static function productKey(array $u): string
    {
        $pid = (int)($u['product_id'] ?? 0);
        return $pid > 0 ? 'p:' . $pid : 't:' . md5((string)($u['title'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $units
     * @return list<array{id:int,starts_at:string,ends_at:string}>
     */
    public static function planUnits(?int $staffUserId, array $units): array
    {
        if (!$units) {
            return [];
        }
        $rate = self::trayRateFor($staffUserId);
        $ids = [];
        foreach ($units as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $used = [];
        $productDay = [];
        if ($staffUserId) {
            foreach (self::staffDayLoad($staffUserId, array_keys($ids)) as $day => $n) {
                $used[$day] = $n;
            }
            $rows = Database::pdo()->prepare(
                "SELECT wo.* FROM work_orders wo
                 JOIN orders o ON o.id = wo.order_id
                 WHERE wo.staff_user_id = ? AND o.status = 'provisioning'"
            );
            $rows->execute([$staffUserId]);
            foreach (self::unitsFromRows($rows->fetchAll()) as $u) {
                if (isset($ids[(int)$u['id']])) {
                    continue;
                }
                $start = (string)($u['starts_at'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    continue;
                }
                $key = self::productKey($u);
                if (!isset($productDay[$key]) || $start < $productDay[$key]) {
                    $productDay[$key] = $start;
                }
            }
        }

        $blocks = [];
        foreach ($units as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $oid = (int)($u['order_id'] ?? 0);
            $pid = (int)($u['parent_id'] ?? 0);
            $blockKey = ($pid > 0 ? $pid : $id) . ':' . $oid;
            if (!isset($blocks[$blockKey])) {
                $blocks[$blockKey] = [
                    'key' => self::productKey($u),
                    'product_id' => (int)($u['product_id'] ?? 0),
                    'parent_id' => $pid,
                    'order_id' => $oid,
                    'units' => [],
                ];
            }
            $blocks[$blockKey]['units'][] = $u;
            if ((int)($u['product_id'] ?? 0) > 0) {
                $blocks[$blockKey]['product_id'] = (int)$u['product_id'];
                $blocks[$blockKey]['key'] = self::productKey($u);
            }
        }

        foreach ($blocks as &$block) {
            usort($block['units'], static function (array $a, array $b): int {
                $ta = (int)($a['tray_no'] ?? 0);
                $tb = (int)($b['tray_no'] ?? 0);
                return $ta === $tb ? ((int)$a['id'] <=> (int)$b['id']) : ($ta <=> $tb);
            });
        }
        unset($block);

        $anchor = date('Y-m-d');
        $list = array_values($blocks);
        usort($list, static function (array $a, array $b) use ($productDay): int {
            $am = isset($productDay[$a['key']]) ? 0 : 1;
            $bm = isset($productDay[$b['key']]) ? 0 : 1;
            if ($am !== $bm) {
                return $am <=> $bm;
            }
            if ($am === 0) {
                $cmp = strcmp((string)$productDay[$a['key']], (string)$productDay[$b['key']]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            if ($a['key'] !== $b['key']) {
                return strcmp($a['key'], $b['key']);
            }
            $na = count($a['units']);
            $nb = count($b['units']);
            if ($na !== $nb) {
                return $nb <=> $na;
            }
            return ((int)$a['order_id'] <=> (int)$b['order_id']) ?: ((int)$a['parent_id'] <=> (int)$b['parent_id']);
        });

        $out = [];
        foreach ($list as $block) {
            $n = count($block['units']);
            $key = $block['key'];
            $seed = $productDay[$key] ?? $anchor;
            $firstDay = $seed;
            if ($n <= $rate) {
                $day = self::firstDayWithCapacity($used, $rate, $seed, $n);
                $firstDay = $day;
                foreach ($block['units'] as $u) {
                    $out[] = ['id' => (int)$u['id'], 'starts_at' => $day, 'ends_at' => $day];
                    $used[$day] = (int)($used[$day] ?? 0) + 1;
                }
            } else {
                $day = self::firstDayWithRoom($used, $rate, $seed);
                foreach ($block['units'] as $i => $u) {
                    while ((int)($used[$day] ?? 0) >= $rate) {
                        $day = date('Y-m-d', strtotime($day . ' +1 day'));
                    }
                    if ($i === 0) {
                        $firstDay = $day;
                    }
                    $out[] = ['id' => (int)$u['id'], 'starts_at' => $day, 'ends_at' => $day];
                    $used[$day] = (int)($used[$day] ?? 0) + 1;
                }
            }
            if (!isset($productDay[$key])) {
                $productDay[$key] = $firstDay;
            }
        }
        return $out;
    }

    /** @param array<string,int> $used */
    private static function firstDayWithRoom(array $used, int $rate, string $start): string
    {
        $day = $start !== '' ? $start : date('Y-m-d');
        while ((int)($used[$day] ?? 0) >= $rate) {
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
        }
        return $day;
    }

    /** First day from $start that can take $n trays without exceeding the rate. */
    private static function firstDayWithCapacity(array $used, int $rate, string $start, int $n): string
    {
        if ($n > $rate) {
            return self::firstDayWithRoom($used, $rate, $start);
        }
        $day = $start !== '' ? $start : date('Y-m-d');
        while (((int)($used[$day] ?? 0) + $n) > $rate) {
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
        }
        return $day;
    }

    /**
     * Re-pack selected orders, or only conflict-affected units (plus sibling
     * trays so a work order stays together).
     *
     * @param list<int> $orderIds
     * @param list<int> $unitIds
     * @return array{ok:bool,items:list<array{id:int,starts_at:string,ends_at:string}>,snapshot:list<array{id:int,starts_at:?string,ends_at:?string}>,conflicts:list<array<string,mixed>>}
     */
    public static function autoSchedule(array $orderIds = [], array $unitIds = []): array
    {
        $all = self::listAll();
        $units = self::unitsFromRows($all);
        $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn($n) => $n > 0)));
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($n) => $n > 0)));

        if ($unitIds) {
            $want = array_fill_keys($unitIds, true);
            $parents = [];
            foreach ($units as $u) {
                if (!isset($want[(int)$u['id']])) {
                    continue;
                }
                $pid = (int)($u['parent_id'] ?? 0);
                $parents[$pid > 0 ? $pid : (int)$u['id']] = true;
            }
            $units = array_values(array_filter($units, static function (array $u) use ($want, $parents): bool {
                $id = (int)$u['id'];
                $pid = (int)($u['parent_id'] ?? 0);
                return isset($want[$id]) || ($pid > 0 && isset($parents[$pid])) || isset($parents[$id]);
            }));
        } elseif ($orderIds) {
            $oids = array_fill_keys($orderIds, true);
            $units = array_values(array_filter(
                $units,
                static fn(array $u): bool => isset($oids[(int)$u['order_id']])
            ));
        }

        $ids = [];
        foreach ($units as $u) {
            $ids[] = (int)$u['id'];
        }
        $snapshot = self::snapshotDates($ids);
        $byStaff = [];
        foreach ($units as $u) {
            $byStaff[(int)($u['staff_user_id'] ?? 0)][] = $u;
        }
        $items = [];
        foreach ($byStaff as $sid => $group) {
            foreach (self::planUnits($sid > 0 ? $sid : null, $group) as $it) {
                self::setSchedule((int)$it['id'], $it['starts_at'], $it['ends_at']);
                $items[] = $it;
            }
            $parents = [];
            foreach ($group as $u) {
                $pid = (int)($u['parent_id'] ?? 0);
                if ($pid > 0) {
                    $parents[$pid] = true;
                }
            }
            foreach (array_keys($parents) as $pid) {
                self::syncParentSpan($pid);
            }
        }
        return [
            'ok' => true,
            'items' => $items,
            'snapshot' => $snapshot,
            'conflicts' => self::scheduleConflicts(),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,starts_at:?string,ends_at:?string}>
     */
    public static function snapshotDates(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($n) => $n > 0)));
        if (!$ids) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare("SELECT id, starts_at, ends_at FROM work_orders WHERE id IN ({$place})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'starts_at' => $row['starts_at'] !== null && $row['starts_at'] !== '' ? (string)$row['starts_at'] : null,
                'ends_at' => $row['ends_at'] !== null && $row['ends_at'] !== '' ? (string)$row['ends_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{id?:mixed,starts_at?:mixed,ends_at?:mixed}> $items
     */
    public static function restoreDates(array $items): int
    {
        return self::setScheduleBatch($items);
    }

    /**
     * @param list<int> $excludeIds
     * @return array<string,int>
     */
    public static function staffDayLoad(int $staffUserId, array $excludeIds = []): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->prepare(
            "SELECT wo.* FROM work_orders wo
             JOIN orders o ON o.id = wo.order_id
             WHERE wo.staff_user_id = ? AND o.status = 'provisioning'"
        );
        $rows->execute([$staffUserId]);
        $skip = array_fill_keys(array_map('intval', $excludeIds), true);
        $load = [];
        foreach (self::unitsFromRows($rows->fetchAll()) as $u) {
            $id = (int)$u['id'];
            if (isset($skip[$id])) {
                continue;
            }
            $start = (string)($u['starts_at'] ?? '');
            $end = (string)($u['ends_at'] ?? $start);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
                $end = $start;
            }
            $d = $start;
            while ($d <= $end) {
                $load[$d] = (int)($load[$d] ?? 0) + 1;
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
        }
        return $load;
    }

    /**
     * Days/staff where scheduled units exceed tray_rate.
     *
     * @return list<array{staff_user_id:int,staff_name:string,day:string,count:int,rate:int,ids:list<int>}>
     */
    public static function scheduleConflicts(): array
    {
        $rows = self::listAll();
        $units = self::unitsFromRows($rows);
        $byStaff = [];
        $names = [];
        $rates = [];
        foreach ($units as $u) {
            $sid = (int)($u['staff_user_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $byStaff[$sid][] = $u;
            $names[$sid] = trim((string)($u['staff_name'] ?? '')) ?: (string)($u['staff_email'] ?? 'Staff');
            $rates[$sid] = max(1, (int)($u['staff_tray_rate'] ?? 0) ?: 10);
        }
        $out = [];
        foreach ($byStaff as $sid => $group) {
            $days = [];
            foreach ($group as $u) {
                $start = (string)($u['starts_at'] ?? '');
                $end = (string)($u['ends_at'] ?? $start);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
                    $end = $start;
                }
                $d = $start;
                while ($d <= $end) {
                    $days[$d]['ids'][] = (int)$u['id'];
                    $days[$d]['count'] = (int)($days[$d]['count'] ?? 0) + 1;
                    $d = date('Y-m-d', strtotime($d . ' +1 day'));
                }
            }
            $rate = $rates[$sid];
            foreach ($days as $day => $info) {
                if ((int)$info['count'] > $rate) {
                    $out[] = [
                        'staff_user_id' => $sid,
                        'staff_name' => $names[$sid],
                        'day' => $day,
                        'count' => (int)$info['count'],
                        'rate' => $rate,
                        'ids' => array_values(array_unique($info['ids'])),
                    ];
                }
            }
        }
        return $out;
    }

    /** @return array<int,int> staff id => tray rate */
    public static function staffRates(): array
    {
        $out = [];
        foreach (Database::pdo()->query("SELECT id, tray_rate FROM users WHERE role = 'staff'") as $row) {
            $out[(int)$row['id']] = max(1, (int)($row['tray_rate'] ?? 10) ?: 10);
        }
        return $out;
    }

    private static function syncParentSpan(int $parentId): void
    {
        $pdo = Database::pdo();
        $trays = $pdo->prepare(
            "SELECT starts_at, ends_at FROM work_orders WHERE parent_id = ? AND starts_at IS NOT NULL AND starts_at <> ''"
        );
        $trays->execute([$parentId]);
        $rows = $trays->fetchAll();
        if (!$rows) {
            return;
        }
        $starts = array_column($rows, 'starts_at');
        $ends = array_map(static fn($r) => $r['ends_at'] ?: $r['starts_at'], $rows);
        $min = min($starts);
        $max = max($ends);
        $pdo->prepare("UPDATE work_orders SET starts_at = ?, ends_at = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$min, $max, $parentId]);
    }

    /**
     * Super Admin approval: mark the client order completed and credit
     * warehouse stock with any excess produced on completed trays.
     */
    public static function approveOrder(int $orderId): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT status, user_id FROM orders WHERE id = ?");
        $st->execute([$orderId]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'provisioning') {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count(self::DONE_STATUSES), '?'));
        $open = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE order_id = ? AND status NOT IN ({$placeholders})");
        $open->execute(array_merge([$orderId], self::DONE_STATUSES));
        if ((int)$open->fetchColumn() > 0) {
            return false;
        }
        self::creditWarehouseExcess($orderId);
        $pdo->prepare("UPDATE orders SET status = 'completed', updated_at = datetime('now') WHERE id = ?")
            ->execute([$orderId]);
        $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
            ->execute([Auth::id(), 'work_order', 'Super Admin approved order #' . $orderId . ' as completed']);
        Alerts::log((int)$row['user_id'], 'order_completed', 'Order #' . $orderId . ' was completed');
        return true;
    }

    /** Credit product warehouse_stock with produced-minus-ordered on each parent line. */
    public static function creditWarehouseExcess(int $orderId): void
    {
        $pdo = Database::pdo();
        $parents = $pdo->prepare(
            'SELECT id, product_id, qty FROM work_orders WHERE order_id = ? AND (parent_id IS NULL OR parent_id = 0)'
        );
        $parents->execute([$orderId]);
        $trayQ = $pdo->prepare('SELECT qty, status FROM work_orders WHERE parent_id = ?');
        $upd = $pdo->prepare('UPDATE products SET warehouse_stock = warehouse_stock + ?, updated_at = datetime(\'now\') WHERE id = ?');
        foreach ($parents->fetchAll() as $parent) {
            $pid = (int)($parent['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $required = max(0, (int)$parent['qty']);
            $trayQ->execute([(int)$parent['id']]);
            $trays = $trayQ->fetchAll();
            $produced = 0;
            if ($trays) {
                foreach ($trays as $t) {
                    if ((string)$t['status'] === 'complete') {
                        $produced += max(0, (int)$t['qty']);
                    }
                }
            } else {
                $produced = $required;
            }
            $excess = $produced - $required;
            if ($excess > 0) {
                $upd->execute([$excess, $pid]);
            }
        }
    }

    private static function maybeNotify(int $orderId): void
    {
        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count(self::DONE_STATUSES), '?'));
        $open = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE order_id = ? AND status NOT IN ({$placeholders})");
        $open->execute(array_merge([$orderId], self::DONE_STATUSES));
        if ((int)$open->fetchColumn() > 0) {
            return;
        }
        $already = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE order_id = ? AND notified_at IS NOT NULL");
        $already->execute([$orderId]);
        if ((int)$already->fetchColumn() > 0) {
            return;
        }
        $pdo->prepare("UPDATE work_orders SET notified_at = datetime('now') WHERE order_id = ?")->execute([$orderId]);
        $client = $pdo->prepare(
            "SELECT TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS name, u.email
             FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?"
        );
        $client->execute([$orderId]);
        $c = $client->fetch() ?: ['name' => '', 'email' => ''];
        $label = trim((string)($c['name'] ?? '')) ?: (string)($c['email'] ?? '');
        $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
            ->execute([0, 'work_order_ready', 'Order #' . $orderId . ' for ' . $label . ' is complete and ready for Super Admin approval']);
    }
}
