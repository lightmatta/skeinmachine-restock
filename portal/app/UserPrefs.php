<?php
declare(strict_types=1);

namespace App;

/**
 * Per-user UI preferences (column heading visibility, etc.).
 * Superuser uses user_id 0.
 */
class UserPrefs
{
    public const GRID_PRODUCTS = 'grid.hidden.products';
    public const GRID_ORDERS = 'grid.hidden.orders';
    public const GRID_USERS = 'grid.hidden.users';
    public const GRID_VENDORS = 'grid.hidden.vendors';
    public const GRID_VENDOR_PRODUCTS = 'grid.hidden.vendor_products';
    public const GRID_SOURCES = 'grid.hidden.sources';
    public const GANTT_VIEW = 'gantt.schedule.view';
    public const GRID_KEYS = [self::GRID_PRODUCTS, self::GRID_ORDERS, self::GRID_USERS, self::GRID_VENDORS, self::GRID_VENDOR_PRODUCTS, self::GRID_SOURCES];
    public const PREF_KEYS = [self::GRID_PRODUCTS, self::GRID_ORDERS, self::GRID_USERS, self::GRID_VENDORS, self::GRID_VENDOR_PRODUCTS, self::GRID_SOURCES, self::GANTT_VIEW];

    /**
     * @param mixed $value
     * @return array{visible_orders:list<int>,known_orders:list<int>,collapsed_orders:list<int>,collapsed_parents:list<int>,match_lines:bool,override_rate:bool,from:?string,to:?string}
     */
    public static function normalizeGanttView($value): array
    {
        $value = is_array($value) ? $value : [];
        $ints = static function ($arr): array {
            $out = [];
            foreach ((array)$arr as $v) {
                if (is_numeric($v)) {
                    $out[] = (int)$v;
                }
            }
            return array_values(array_unique($out));
        };
        $date = static function ($v): ?string {
            $s = trim((string)$v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
        };
        return [
            'visible_orders'    => $ints($value['visible_orders'] ?? []),
            'known_orders'      => $ints($value['known_orders'] ?? []),
            'collapsed_orders'  => $ints($value['collapsed_orders'] ?? []),
            'collapsed_parents' => $ints($value['collapsed_parents'] ?? []),
            'match_lines'       => !empty($value['match_lines']),
            'override_rate'     => !empty($value['override_rate']),
            'from'              => $date($value['from'] ?? ''),
            'to'                => $date($value['to'] ?? ''),
        ];
    }

    public static function get(int $userId, string $key, ?string $default = null): ?string
    {
        $stmt = Database::pdo()->prepare(
            'SELECT value FROM user_prefs WHERE user_id = ? AND pref_key = ?'
        );
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return $default;
        }
        return (string)$row;
    }

    /** @param mixed $default */
    public static function getJson(int $userId, string $key, $default = null)
    {
        $raw = self::get($userId, $key, null);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? $default : $decoded;
    }

    /** @param mixed $value */
    public static function set(int $userId, string $key, $value): void
    {
        $stored = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_prefs (user_id, pref_key, value) VALUES (?,?,?)
             ON CONFLICT(user_id, pref_key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$userId, $key, (string)$stored]);
    }

    /**
     * Hidden DataGrid column keys for this user. Missing prefs return $defaults;
     * an explicit empty list means “show every column”.
     *
     * @param list<string> $defaults
     * @return list<string>
     */
    public static function hiddenColumns(int $userId, string $key, array $defaults = []): array
    {
        $val = self::getJson($userId, $key, null);
        if (!is_array($val)) {
            return array_values($defaults);
        }
        $out = [];
        foreach ($val as $col) {
            if (is_string($col) && $col !== '') {
                $out[] = $col;
            }
        }
        return array_values(array_unique($out));
    }
}
