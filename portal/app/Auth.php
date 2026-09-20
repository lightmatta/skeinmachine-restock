<?php
declare(strict_types=1);

namespace App;

/**
 * Authentication + authorization.
 *
 * The singular overarching SUPERUSER is authenticated exclusively from
 * config.php. A sentinel users row (id 0) exists for legacy foreign keys.
 * In-database "admin" users hold full system-administration privileges.
 * Staff users see the restock portal plus a limited admin shell.
 */
class Auth
{
    private static array $config = [];

    public static function boot(array $config): void
    {
        self::$config = $config;
        $s = $config['session'] ?? [];
        session_name($s['name'] ?? 'HDPORTAL');
        session_set_cookie_params([
            'lifetime' => $s['lifetime'] ?? 0,
            'httponly' => true,
            'secure'   => (bool)($s['cookie_secure'] ?? false),
            'samesite' => $s['cookie_samesite'] ?? 'Lax',
        ]);
        session_start();
    }

    /** Attempt to authenticate by username/email + password. */
    public static function attempt(string $identifier, string $password): bool
    {
        $identifier = trim($identifier);

        // 1) Overarching superuser (config-only).
        $su = self::$config['superuser'] ?? null;
        if ($su && ($identifier === $su['username'] || strcasecmp($identifier, (string)($su['email'] ?? '')) === 0)) {
            if (password_verify($password, $su['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'           => 0,
                    'role'         => 'superuser',
                    'email'        => $su['email'] ?? '',
                    'display_name' => $su['display_name'] ?? 'Superuser',
                    'phone'        => $su['phone'] ?? '',
                ];
                return true;
            }
            return false;
        }

        // 2) Database users (admin / staff).
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($identifier)]);
        $user = $stmt->fetch();
        if (!$user || !$user['password_hash'] || $user['status'] === 'disabled') {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'           => (int)$user['id'],
            'role'         => $user['role'],
            'email'        => $user['email'],
            'display_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email'],
            'phone'        => $user['phone'] ?? '',
        ];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        $u = $_SESSION['user'] ?? null;
        if ($u && in_array((string)($u['role'] ?? ''), ['wholesale', 'guest'], true)) {
            $u['role'] = 'staff';
            $_SESSION['user'] = $u;
        }
        return $u;
    }

    /** Full DB row for the current user (null for superuser / guests). */
    public static function dbUser(): ?array
    {
        $u = self::user();
        if (!$u || (int)$u['id'] === 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$u['id']]);
        return $stmt->fetch() ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isSuperuser(): bool
    {
        return (self::user()['role'] ?? '') === 'superuser';
    }

    /** Superuser OR admin — full administrative access. */
    public static function isAdmin(): bool
    {
        $role = self::user()['role'] ?? '';
        return $role === 'admin' || $role === 'superuser';
    }

    public static function isStaff(): bool
    {
        return (self::user()['role'] ?? '') === 'staff';
    }

    public static function isStaffOrAdmin(): bool
    {
        return self::isAdmin() || self::isStaff();
    }

    public static function isWholesale(): bool
    {
        return false;
    }

    public static function canOrder(): bool
    {
        return false;
    }

    /** @deprecated Shopping cart has been removed. */
    public static function requireShopper(): void
    {
        abort(404, 'Page not found.');
    }

    /**
     * users.id used for legacy order foreign keys. The Super Admin session is
     * id 0; a sentinel users row is created so leftover rows can reference it.
     */
    public static function shopperId(): int
    {
        $id = self::id();
        if ($id > 0) {
            return $id;
        }
        return self::ensureShopperUser();
    }

    /** Ensure the Super Admin sentinel users row exists (id 0). */
    public static function ensureShopperUser(): int
    {
        $pdo = Database::pdo();
        $have = $pdo->query('SELECT id FROM users WHERE id = 0')->fetchColumn();
        if ($have !== false) {
            return 0;
        }
        $email = '__superadmin__@housedye.internal';
        $pdo->prepare(
            "INSERT INTO users (id, email, password_hash, role, status, first_name, last_name)
             VALUES (0, ?, '', 'admin', 'active', 'Super', 'Admin')"
        )->execute([$email]);
        return 0;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? -1);
    }

    /** Require login or redirect to the login page. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login');
        }
    }

    /** Require admin privileges or abort. */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            abort(403, 'Administrator access required.');
        }
    }

    /** Require staff or admin privileges or abort. */
    public static function requireStaffOrAdmin(): void
    {
        self::requireLogin();
        if (!self::isStaffOrAdmin()) {
            abort(403, 'Staff access required.');
        }
    }

    /** Record that an admin is present (called on admin page/api activity). */
    public static function touchAdminPresence(): void
    {
        if (self::isAdmin()) {
            Database::pdo()->exec("UPDATE admin_presence SET last_seen = datetime('now') WHERE id = 1");
        }
    }

    /** Whether an admin has been active within the configured window. */
    public static function adminOnline(): bool
    {
        $minutes = (int)(self::$config['admin_presence_minutes'] ?? 5);
        $row = Database::pdo()->query('SELECT last_seen FROM admin_presence WHERE id = 1')->fetch();
        if (!$row || !$row['last_seen']) {
            return false;
        }
        $seen = strtotime($row['last_seen'] . ' UTC');
        return $seen !== false && (time() - $seen) <= $minutes * 60;
    }
}
