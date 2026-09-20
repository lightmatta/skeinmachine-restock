<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Settings;
use PDO;

/**
 * Token-authenticated JSON API for the mobile clients (and any integration).
 *
 * Auth: POST ?r=api&a=login with {email,password} → { token }. Send the token
 * on subsequent calls as `Authorization: Bearer <token>` (or ?token=). Tokens
 * are stored only as salted hashes; the plaintext is shown once at login.
 */
class ApiController
{
    public static function handle(): void
    {
        header('Content-Type: application/json');
        $action = (string)($_GET['a'] ?? '');

        if ($action === 'login') { self::login(); return; }
        if ($action === 'ping')  { json_response(['ok' => true, 'service' => Settings::get('company_name', 'HouseDye')]); }

        // All other actions require a valid bearer token.
        $user = self::authUser();
        if (!$user) {
            json_response(['error' => 'unauthorized'], 401);
        }

        switch ($action) {
            case 'me':
                json_response(['user' => self::publicUser($user)]);
                break;

            case 'products':
                json_response(['products' => self::products($user)]);
                break;

            case 'orders':
                self::requireWholesale($user);
                $stmt = Database::pdo()->prepare('SELECT id, status, payment_status, total_cents, tracking_url, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC');
                $stmt->execute([(int)$user['id']]);
                json_response(['orders' => $stmt->fetchAll()]);
                break;

            case 'order':
                self::requireWholesale($user);
                $id = (int)($_GET['id'] ?? 0);
                $o = Database::pdo()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
                $o->execute([$id, (int)$user['id']]);
                $order = $o->fetch();
                if (!$order) json_response(['error' => 'not_found'], 404);
                $items = Database::pdo()->prepare('SELECT title, qty, unit_price_cents, line_total_cents FROM order_items WHERE order_id = ?');
                $items->execute([$id]);
                json_response(['order' => $order, 'items' => $items->fetchAll()]);
                break;

            case 'messages':
                $after = (int)($_GET['after'] ?? 0);
                $stmt = Database::pdo()->prepare('SELECT id, sender, body, created_at FROM messages WHERE thread_user_id = ? AND id > ? ORDER BY id');
                $stmt->execute([(int)$user['id'], $after]);
                json_response([
                    'messages' => $stmt->fetchAll(),
                    'admin_online' => Auth::adminOnline(),
                ]);
                break;

            case 'send_message':
                $body = trim((string)(request_json()['body'] ?? ''));
                if ($body === '') json_response(['error' => 'empty'], 400);
                $pdo = Database::pdo();
                $pdo->prepare("INSERT INTO messages (thread_user_id, sender, body) VALUES (?, 'client', ?)")
                    ->execute([(int)$user['id'], mb_substr($body, 0, 2000)]);
                // Reuse the same offline auto-responder rules as the web chat.
                if (!Auth::adminOnline()) {
                    $reply = self::autoReply($body);
                    $pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, read_by_client) VALUES (?, 'system', ?, 1)")
                        ->execute([(int)$user['id'], $reply]);
                }
                json_response(['ok' => true]);
                break;

            case 'notifications':
                // Count for push/badge: unread admin+system messages for this client.
                $n = Database::pdo()->prepare("SELECT COUNT(*) FROM messages WHERE thread_user_id = ? AND sender <> 'client' AND read_by_client = 0");
                $n->execute([(int)$user['id']]);
                json_response(['unread_messages' => (int)$n->fetchColumn()]);
                break;

            case 'logout':
                self::revokeToken();
                json_response(['ok' => true]);
                break;

            default:
                json_response(['error' => 'unknown_action'], 400);
        }
    }

    private static function login(): void
    {
        $body = request_json();
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? AND status <> "disabled"');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            json_response(['error' => 'invalid_credentials'], 401);
        }
        $token = bin2hex(random_bytes(32));
        Database::pdo()->prepare('INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?,?,?)')
            ->execute([(int)$user['id'], self::hashToken($token), 'mobile']);
        json_response(['token' => $token, 'user' => self::publicUser($user)]);
    }

    private static function authUser(): ?array
    {
        $token = '';
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) $token = $m[1];
        if ($token === '') $token = (string)($_GET['token'] ?? '');
        if ($token === '') return null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT t.id AS token_id, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? LIMIT 1');
        $stmt->execute([self::hashToken($token)]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare("UPDATE api_tokens SET last_used = datetime('now') WHERE id = ?")->execute([(int)$row['token_id']]);
        $_SESSION['__api_token'] = $token;
        return $row;
    }

    private static function revokeToken(): void
    {
        $token = $_SESSION['__api_token'] ?? '';
        if ($token) {
            Database::pdo()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([self::hashToken($token)]);
        }
    }

    private static function hashToken(string $token): string
    {
        $secret = $GLOBALS['config']['app_secret'] ?? '';
        return hash('sha256', $token . '|' . $secret);
    }

    private static function products(array $user): array
    {
        $pdo = Database::pdo();
        if (($user['role'] ?? '') === 'wholesale') {
            $sql = "SELECT id, sku, title, description, category, price_cents AS retail_cents";
            if (\App\Settings::wholesaleShowStock()) {
                $sql .= ', stock';
            }
            $sql .= " FROM products WHERE is_public = 1 AND archived = 0 ORDER BY title";
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $row['price_cents'] = \App\Settings::wholesaleCents((int)$row['retail_cents'], $user);
            }
            unset($row);
            return $rows;
        }
        // Guests/others: public listing without price/stock.
        return $pdo->query('SELECT id, title, description, category FROM products WHERE is_public = 1 AND archived = 0 ORDER BY title')->fetchAll();
    }

    private static function requireWholesale(array $user): void
    {
        if (($user['role'] ?? '') !== 'wholesale') {
            json_response(['error' => 'wholesale_required'], 403);
        }
    }

    private static function publicUser(array $user): array
    {
        return [
            'id'    => (int)$user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'name'  => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        ];
    }

    private static function autoReply(string $message): string
    {
        $phone = Settings::get('contact_phone', '');
        $holding = "Thanks for your message! We're not able to respond immediately right now, but we'll reply as soon as we can.";
        if ($phone) $holding .= " If it's urgent, call us on {$phone}.";
        return $holding;
    }
}
