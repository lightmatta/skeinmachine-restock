<?php
declare(strict_types=1);

namespace App;

/**
 * Shopify collection sources: one vendor + collection per row, with
 * per-source sync and an optional day-spread automated schedule.
 */
class Sources
{
    /** @return list<array<string,mixed>> */
    public static function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM sources';
        if (!$includeArchived) {
            $sql .= ' WHERE archived = 0';
        }
        $sql .= ' ORDER BY vendor_name COLLATE NOCASE, collection_name COLLATE NOCASE, id';
        return Database::pdo()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sources WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(): int
    {
        Database::pdo()->prepare(
            "INSERT INTO sources (vendor_name, collection_id, collection_name, sync_frequency_days) VALUES ('New vendor', '', '', 7)"
        )->execute();
        $id = (int)Database::pdo()->lastInsertId();
        self::rescheduleAll();
        return $id;
    }

    /**
     * @param array<string,mixed> $changes
     */
    public static function update(int $id, array $changes): void
    {
        $allowed = ['vendor_name', 'collection_id', 'collection_name', 'sync_frequency_days', 'archived'];
        $sets = [];
        $vals = [];
        foreach ($changes as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            if ($col === 'sync_frequency_days') {
                $val = max(1, (int)$val);
            }
            if ($col === 'archived') {
                $val = (int)$val ? 1 : 0;
            }
            if ($col === 'collection_id' || $col === 'vendor_name' || $col === 'collection_name') {
                $val = trim((string)$val);
            }
            $sets[] = "$col = ?";
            $vals[] = $val;
        }
        if (!$sets) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        Database::pdo()->prepare('UPDATE sources SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        self::rescheduleAll();
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('UPDATE products SET source_id = NULL WHERE source_id = ?')->execute([$id]);
        Database::pdo()->prepare('DELETE FROM sources WHERE id = ?')->execute([$id]);
        self::rescheduleAll();
    }

    public static function automatedEnabled(): bool
    {
        return Settings::get('allow_automated_sync', '0') === '1';
    }

    /**
     * Evenly spaced clock times for $count jobs on $day (Y-m-d).
     *
     * @return list<string> Y-m-d H:i:s
     */
    public static function spreadTimes(string $day, int $count): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $count < 1) {
            return [];
        }
        $start = strtotime($day . ' 00:00:00');
        if ($start === false) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = date('Y-m-d H:i:s', $start + (int)(($i + 0.5) * 86400 / $count));
        }
        return $out;
    }

    /** Calendar day a source is next due, based on last sync + frequency. */
    public static function dueDay(array $source, int $now = 0): string
    {
        $now = $now > 0 ? $now : time();
        $freq = max(1, (int)($source['sync_frequency_days'] ?? 7));
        $last = trim((string)($source['last_sync_at'] ?? ''));
        $lastTs = $last !== '' ? (strtotime($last) ?: 0) : 0;
        $due = $lastTs > 0 ? $lastTs + ($freq * 86400) : $now;
        if ($due < $now) {
            $due = $now;
        }
        return date('Y-m-d', $due);
    }

    /**
     * Assign next_sync_at so sources due on the same day are spread evenly.
     */
    public static function rescheduleAll(?int $now = null): void
    {
        $now = $now ?? time();
        $pdo = Database::pdo();
        if (!self::automatedEnabled()) {
            $pdo->exec('UPDATE sources SET next_sync_at = NULL');
            return;
        }
        $rows = $pdo->query(
            "SELECT * FROM sources WHERE archived = 0 AND TRIM(COALESCE(collection_id, '')) <> ''
             ORDER BY id"
        )->fetchAll();
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[self::dueDay($row, $now)][] = $row;
        }
        $upd = $pdo->prepare(
            "UPDATE sources SET next_sync_at = ?, updated_at = datetime('now') WHERE id = ?"
        );
        foreach ($byDay as $day => $list) {
            $times = self::spreadTimes($day, count($list));
            foreach ($list as $i => $row) {
                $upd->execute([$times[$i] ?? null, (int)$row['id']]);
            }
        }
    }

    /**
     * Sync one due source (or the forced id). One source per tick so we do not
     * burst Shopify.
     *
     * @return array<string,mixed>
     */
    public static function tickDue(bool $force = false, ?int $onlyId = null): array
    {
        if (!$force && !self::automatedEnabled()) {
            return ['ok' => true, 'ran' => false, 'reason' => 'off'];
        }
        $pdo = Database::pdo();
        if ($onlyId) {
            $row = self::find($onlyId);
            $due = $row ? [$row] : [];
        } else {
            $due = $pdo->query(
                "SELECT * FROM sources
                 WHERE archived = 0 AND TRIM(COALESCE(collection_id, '')) <> ''
                   AND next_sync_at IS NOT NULL AND next_sync_at <> ''
                   AND datetime(next_sync_at) <= datetime('now')
                 ORDER BY next_sync_at, id LIMIT 1"
            )->fetchAll();
        }
        if (!$due) {
            if (self::automatedEnabled()) {
                $unscheduled = (int)$pdo->query(
                    "SELECT COUNT(*) FROM sources WHERE archived = 0
                     AND TRIM(COALESCE(collection_id, '')) <> ''
                     AND (next_sync_at IS NULL OR next_sync_at = '')"
                )->fetchColumn();
                if ($unscheduled > 0) {
                    self::rescheduleAll();
                }
            }
            return ['ok' => true, 'ran' => false, 'reason' => 'waiting'];
        }
        $source = $due[0];
        $result = self::syncNow((int)$source['id']);
        $result['ran'] = (bool)($result['ok'] ?? false);
        $result['source_id'] = (int)$source['id'];
        return $result;
    }

    /**
     * Pull Shopify products for this source's collection into the catalog.
     *
     * @return array<string,mixed>
     */
    public static function syncNow(int $id): array
    {
        $source = self::find($id);
        if (!$source || (int)$source['archived'] === 1) {
            return ['ok' => false, 'error' => 'Source not found.', 'message' => 'Source not found.'];
        }
        $collectionId = trim((string)($source['collection_id'] ?? ''));
        if ($collectionId === '') {
            return [
                'ok' => false,
                'error' => 'no_collection',
                'message' => 'Add a Collection ID before syncing this source.',
            ];
        }
        if (!ShopifyService::configured()) {
            return [
                'ok' => false,
                'error' => 'not_configured',
                'message' => 'Set the Shopify store domain, Client ID, and Client secret in Settings first.',
            ];
        }
        try {
            $title = ShopifyService::fetchCollectionTitle($collectionId);
            if ($title !== '') {
                Database::pdo()->prepare(
                    "UPDATE sources SET collection_name = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([$title, $id]);
                $source['collection_name'] = $title;
            }
            $items = ShopifyService::fetchCollectionProducts($collectionId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()];
        }
        $vendorId = self::ensureVendorId((string)$source['vendor_name']);
        $result = ShopifyService::upsertProducts($items, $id, $vendorId);
        $now = date('Y-m-d H:i:s');
        Database::pdo()->prepare(
            "UPDATE sources SET last_sync_at = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([$now, $id]);
        self::rescheduleAll();
        $result['ok'] = true;
        $result['fetched'] = count($items);
        $result['last_sync_at'] = $now;
        $result['message'] = 'Synced ' . count($items) . ': ' . (int)$result['created'] . ' new, ' . (int)$result['updated'] . ' updated';
        return $result;
    }

    /** Match or create a vendors row so the products grid Vendor column stays filled. */
    public static function ensureVendorId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $pdo = Database::pdo();
        $find = $pdo->prepare('SELECT id FROM vendors WHERE name = ? COLLATE NOCASE LIMIT 1');
        $find->execute([$name]);
        $id = $find->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $pdo->prepare('INSERT INTO vendors (name) VALUES (?)')->execute([$name]);
        return (int)$pdo->lastInsertId();
    }

    public static function displayLabel(string $vendorName, array $collectionNames): string
    {
        $vendorName = trim($vendorName) !== '' ? trim($vendorName) : 'Unassigned';
        $names = [];
        foreach ($collectionNames as $n) {
            $n = trim((string)$n);
            if ($n !== '' && !in_array($n, $names, true)) {
                $names[] = $n;
            }
        }
        natcasesort($names);
        $names = array_values($names);
        if (!$names) {
            return $vendorName;
        }
        return $vendorName . ' / ' . implode(', ', $names);
    }
}
