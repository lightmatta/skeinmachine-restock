<?php
declare(strict_types=1);

namespace App;

/**
 * System-wide settings stored in the `settings` key/value table.
 */
class Settings
{
    private static array $cache = [];

    public static function all(): array
    {
        if (self::$cache) {
            return self::$cache;
        }
        $rows = Database::pdo()->query('SELECT key, value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $value]);
        self::$cache[$key] = $value;
    }

    /** When on, wholesale catalog cards and product pages show stock counts. */
    public static function wholesaleShowStock(): bool
    {
        return self::get('wholesale_show_stock', '0') === '1';
    }

    /** Wholesale sell price as a percent of retail (default 65). */
    public static function wholesalePercent(): int
    {
        $n = (int)self::get('wholesale_percent', '65');
        return max(0, min(100, $n));
    }

    /**
     * Effective wholesale percent of retail for a shopper.
     * users.discount_percent > 0 overrides the global wholesale_percent.
     */
    public static function wholesalePercentFor(?array $user = null): int
    {
        $override = (int)($user['discount_percent'] ?? 0);
        if ($override > 0) {
            return max(1, min(100, $override));
        }
        return self::wholesalePercent();
    }

    public static function wholesaleCents(int $retailCents, ?array $user = null): int
    {
        return (int)round($retailCents * self::wholesalePercentFor($user) / 100);
    }

    /** Storefront price using the logged-in shopper's override, if any. */
    public static function shopperWholesaleCents(int $retailCents): int
    {
        return self::wholesaleCents($retailCents, Auth::dbUser());
    }

    /** Default wholesale minimum order quantity for a product (used on new catalog rows). */
    public static function productMinQty(): int
    {
        return max(1, (int)self::get('product_min_qty', '10'));
    }

    /** Default skeins per tray for a product (used on new catalog rows and tray splits). */
    public static function productSpt(): int
    {
        return max(1, (int)self::get('product_spt', '10'));
    }

    /** Effective SPT for a product row (falls back to the global default). */
    public static function sptForProduct(?array $product): int
    {
        $n = (int)($product['spt'] ?? 0);
        return $n > 0 ? $n : self::productSpt();
    }

    /** Effective minimum for a product row (falls back to the global default). */
    public static function minQtyForProduct(array $product): int
    {
        $n = (int)($product['min_qty'] ?? $product['product_min_qty'] ?? 0);
        return $n > 0 ? $n : self::productMinQty();
    }

    /** Whether a user row is allowed to order below product Min. */
    public static function userIgnoresMin(?array $user): bool
    {
        return $user !== null && (int)($user['ignore_min_quantities'] ?? 0) === 1;
    }

    /**
     * Lowest qty a wholesale client may order of a product.
     * Always at least the products.min_qty column unless Ignore min is set.
     * $bundleMin can raise the floor further for bundle memberships.
     */
    public static function wholesaleFloorQty(array $product, bool $ignoreMin, int $bundleMin = 1): int
    {
        if ($ignoreMin) {
            return 1;
        }
        return max(self::minQtyForProduct($product), max(1, $bundleMin));
    }

    /** ISO currency the company bills in (default AUD). */
    public static function currency(): string
    {
        return Currency::company();
    }

    /** Desktop Notification API for incoming instant messages. */
    public static function imBrowserNotifications(): bool
    {
        return self::get('im_browser_notifications', '0') === '1';
    }

    /** Admin-only alerts for new orders, applications, cancellations, completions. */
    public static function adminEventAlerts(): bool
    {
        return self::get('admin_event_alerts', '0') === '1';
    }

    /** Analyse feature images during Shopify API and CSV imports. */
    public static function detectColoursOnImport(): bool
    {
        return self::get('detect_colours_on_import', '0') === '1';
    }

    /** Default Sources sync frequency when a new row is created. */
    public static function defaultSyncFrequencyDays(): int
    {
        return max(1, (int)self::get('default_sync_frequency_days', '1'));
    }

    /** Pastel restock urgency colours on report rows. */
    public static function reportUrgencyColors(): bool
    {
        return self::get('report_urgency_colors', '1') === '1';
    }
}
