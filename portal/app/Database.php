<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Thin PDO/SQLite wrapper with schema migration and default seeding.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dbConf = $config['db'];
        $path = $dbConf['path'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new \RuntimeException('Database not connected.');
        }
        return self::$pdo;
    }

    /**
     * Run the schema (idempotent) and seed default settings + demo data.
     */
    public static function migrate(string $schemaFile): void
    {
        $pdo = self::pdo();
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read schema: {$schemaFile}");
        }
        $pdo->exec($sql);
        // Idempotent column additions for existing databases.
        self::ensureColumn('products', 'status', "TEXT NOT NULL DEFAULT 'active'");
        self::ensureColumn('products', 'shopify_product_id', 'TEXT');
        self::ensureColumn('products', 'images_json', 'TEXT');
        self::ensureColumn('messages', 'to_user_id', 'INTEGER');
        self::ensureColumn('messages', 'sender_user_id', 'INTEGER');
        self::ensureColumn('products', 'min_qty', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('products', 'goal_qty', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('products', 'vendor_id', 'INTEGER');
        self::ensureColumn('products', 'source_id', 'INTEGER');
        self::ensureColumn('products', 'spt', 'INTEGER NOT NULL DEFAULT 10');
        self::ensureColumn('products', 'warehouse_stock', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('work_orders', 'notes', 'TEXT');
        self::ensureColumn('work_orders', 'parent_id', 'INTEGER');
        self::ensureColumn('work_orders', 'tray_no', 'INTEGER');
        self::ensureColumn('work_orders', 'starts_at', 'TEXT');
        self::ensureColumn('work_orders', 'ends_at', 'TEXT');
        self::ensureColumn('work_orders', 'qty_was', 'INTEGER');
        self::ensureColumn('work_orders', 'qty_changed_at', 'TEXT');
        self::ensureColumn('users', 'preferred_currency', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn('orders', 'manual_discount_cents', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('products', 'colours', "TEXT NOT NULL DEFAULT ''");
        self::retireWholesaleAccounts();
        self::seedDefaults();
        self::seedSourcesFromVendors();
    }

    /** Add a column to a table only if it does not already exist (SQLite-safe). */
    public static function ensureColumn(string $table, string $column, string $definition): void
    {
        $pdo = self::pdo();
        $exists = false;
        foreach ($pdo->query("PRAGMA table_info(" . $table . ")") as $col) {
            if (($col['name'] ?? '') === $column) { $exists = true; break; }
        }
        if (!$exists) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /** Drop a column when it exists (SQLite 3.35+). */
    public static function dropColumn(string $table, string $column): void
    {
        $pdo = self::pdo();
        $exists = false;
        foreach ($pdo->query("PRAGMA table_info(" . $table . ")") as $col) {
            if (($col['name'] ?? '') === $column) { $exists = true; break; }
        }
        if (!$exists) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        } catch (\Throwable $e) {
            // Older SQLite cannot DROP COLUMN; leave the unused field in place.
        }
    }

    /** Convert leftover wholesale/guest accounts and drop unused user columns. */
    private static function retireWholesaleAccounts(): void
    {
        $pdo = self::pdo();
        try {
            $pdo->exec(
                "UPDATE users SET role = 'staff',
                    status = CASE WHEN status = 'pending' THEN 'disabled' ELSE status END
                 WHERE role IN ('wholesale', 'guest')"
            );
            $pdo->exec("UPDATE users SET status = 'disabled' WHERE status = 'pending'");
        } catch (\Throwable $e) {
            // users table may not exist yet on a brand-new install before schema ran.
        }
        self::dropColumn('users', 'tray_rate');
        self::dropColumn('users', 'discount_percent');
        self::dropColumn('users', 'ignore_min_quantities');
    }

    /** One-time: copy leftover vendors into Sources when the table is empty. */
    private static function seedSourcesFromVendors(): void
    {
        $pdo = self::pdo();
        $have = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sources'")->fetchColumn();
        if (!$have) {
            return;
        }
        $n = (int)$pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn();
        if ($n > 0) {
            return;
        }
        $vendors = $pdo->query("SELECT id, name FROM vendors WHERE archived = 0")->fetchAll();
        if (!$vendors) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO sources (vendor_name, collection_id, collection_name, sync_frequency_days) VALUES (?,?,?,?)'
        );
        $freq = Settings::defaultSyncFrequencyDays();
        foreach ($vendors as $v) {
            $ins->execute([(string)$v['name'], '', (string)$v['name'], $freq]);
            $sid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE products SET source_id = ? WHERE vendor_id = ? AND (source_id IS NULL OR source_id = 0)')
                ->execute([$sid, (int)$v['id']]);
        }
    }

    private static function seedDefaults(): void
    {
        $pdo = self::pdo();

        $defaults = [
            'company_name'        => 'HouseDye Yarns',
            'front_title'         => 'Hand-Dyed Yarns & Maker Goods',
            'highlight_color'     => '#FF6F61',
            'logo_url'            => '',
            'contact_email'       => 'hello@housedye.example',
            'contact_phone'       => '+1-555-0100-200',
            'opening_hours'       => 'Mon–Fri, 9am–5pm',
            'wholesale_signup_enabled' => '1',
            'wholesale_show_stock' => '0',
            'currency'            => 'AUD',
            'wholesale_percent'   => '65',
            'product_min_qty'     => '0',
            'product_spt'         => '10',
            'im_browser_notifications' => '0',
            'admin_event_alerts'  => '0',
            'wholesale_preface'   => 'Thanks for your interest in stocking HouseDye yarns! Please complete the application below. We review new wholesale accounts within 2 business days.',
            'page_about'          => '<h2>About HouseDye</h2><p>We are a small-batch yarn dyeing studio creating vivid, hand-dyed yarns and maker accessories.</p>',
            'page_contact'        => '<h2>Contact Us</h2><p>Email, call, or use the wholesale chat once you have an account.</p>',
            'page_products'       => '<h2>Our Products</h2><p>Explore our hand-dyed yarn ranges and 3D-printed maker accessories below.</p>',
            // Shopify integration: Client ID + secret; Admin token is obtained by the portal.
            'shopify_domain'      => '',
            'shopify_client_id'   => '',
            'shopify_client_secret' => '',
            'shopify_token'       => '',
            'shopify_token_expires_at' => '0',
            'shopify_api_version' => '2024-10',
            'shopify_collection_id' => '',
            'shopify_last_sync'   => '',
            'shopify_last_stock_sync' => '',
            'shopify_periodic_sync' => '0',
            'shopify_periodic_minutes' => '60',
            'shopify_periodic_next_at' => '0',
            'allow_automated_sync' => '0',
            'default_sync_frequency_days' => '1',
            'report_urgency_colors' => '1',
            'detect_colours_on_import' => '0',
        ];
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Ensure a single presence row exists.
        $pdo->exec("INSERT OR IGNORE INTO admin_presence (id, last_seen) VALUES (1, NULL)");
        Auth::ensureShopperUser();
    }
}
