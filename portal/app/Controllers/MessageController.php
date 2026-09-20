<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Alerts;
use App\Auth;
use App\Database;
use App\Settings;

/**
 * Chatbox. Wholesale/guest clients hold one thread with the admin team.
 * Staff messages go to all admins by default, or to a chosen admin/staff
 * account. Admins compose to any user from the Messages section.
 */
class MessageController
{
    public static function poll(): void
    {
        Auth::requireLogin();
        if (Auth::isAdmin()) {
            json_response(['messages' => [], 'admin_online' => true, 'presence' => true]);
        }
        $uid = Auth::id();
        $after = (int)($_GET['after'] ?? 0);
        $to = (int)($_GET['to'] ?? 0);
        $pdo = Database::pdo();

        if (Auth::isStaff() && $to > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, sender, body, created_at, sender_user_id, to_user_id FROM messages
                 WHERE id > ? AND (
                    (sender_user_id = ? AND to_user_id = ?)
                    OR (sender_user_id = ? AND to_user_id = ?)
                    OR (thread_user_id = ? AND to_user_id = ?)
                    OR (thread_user_id = ? AND sender_user_id = ? AND to_user_id = ?)
                 ) ORDER BY id'
            );
            $stmt->execute([$after, $uid, $to, $to, $uid, $uid, $to, $to, $to, $uid]);
        } elseif (Auth::isStaff()) {
            $stmt = $pdo->prepare(
                'SELECT id, sender, body, created_at, sender_user_id, to_user_id FROM messages
                 WHERE thread_user_id = ? AND id > ?
                   AND (
                        sender IN (\'admin\',\'system\')
                        OR to_user_id IS NULL OR to_user_id = 0
                   )
                 ORDER BY id'
            );
            $stmt->execute([$uid, $after]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, sender, body, created_at, sender_user_id, to_user_id FROM messages
                 WHERE thread_user_id = ? AND id > ? ORDER BY id'
            );
            $stmt->execute([$uid, $after]);
        }
        $rows = $stmt->fetchAll();
        if (($_GET['mark'] ?? '') === '1') {
            $pdo->prepare("UPDATE messages SET read_by_client = 1 WHERE (thread_user_id = ? OR to_user_id = ?) AND sender <> 'client' AND sender <> 'staff'")
                ->execute([$uid, $uid]);
        }

        json_response([
            'messages'     => array_map([self::class, 'shape'], $rows),
            'admin_online' => Auth::adminOnline(),
            'presence'     => true,
        ]);
    }

    /** Unread chat + admin-event snapshot. Does not mark messages read. */
    public static function alerts(): void
    {
        Auth::requireLogin();
        try {
            \App\ShopifyService::tickPeriodic();
        } catch (\Throwable $e) {
            // Chat polling must never fail because Shopify is unreachable.
        }
        json_response(Alerts::snapshot(
            (int)($_GET['after'] ?? 0),
            (int)($_GET['after_event'] ?? 0)
        ));
    }

    public static function send(): void
    {
        Auth::requireLogin();
        if (Auth::isAdmin()) {
            json_response(['error' => 'Admins reply from the Messages section.'], 400);
        }
        if (!csrf_check()) json_response(['error' => 'csrf'], 400);

        $uid = Auth::id();
        $payload = request_json();
        $body = trim((string)($payload['body'] ?? ''));
        if ($body === '') json_response(['error' => 'empty'], 400);
        if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

        $pdo = Database::pdo();
        $to = (int)($payload['to_user_id'] ?? 0);
        $sender = Auth::isStaff() ? 'staff' : 'client';

        if (Auth::isStaff() && $to > 0) {
            if ($to === $uid || !self::staffMayMessage($to)) {
                json_response(['error' => 'forbidden', 'message' => 'Staff can only message admins or other staff.'], 403);
            }
            $ins = $pdo->prepare(
                "INSERT INTO messages (thread_user_id, sender, body, read_by_admin, read_by_client, to_user_id, sender_user_id)
                 VALUES (?, ?, ?, 0, 1, ?, ?)"
            );
            $ins->execute([$uid, $sender, $body, $to, $uid]);
        } else {
            // All admins (wholesale clients and staff default).
            $ins = $pdo->prepare(
                "INSERT INTO messages (thread_user_id, sender, body, read_by_admin, to_user_id, sender_user_id)
                 VALUES (?, ?, ?, 0, NULL, ?)"
            );
            $ins->execute([$uid, $sender, $body, $uid]);
        }
        $firstId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
            ->execute([$uid, 'message', 'New chat message from ' . $sender . ' #' . $uid]);

        if (!Auth::isStaff() && !Auth::adminOnline()) {
            $reply = self::autoReply($body);
            $pdo->prepare("INSERT INTO messages (thread_user_id, sender, body, read_by_client, sender_user_id) VALUES (?, 'system', ?, 1, NULL)")
                ->execute([$uid, $reply]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, sender, body, created_at, sender_user_id, to_user_id FROM messages WHERE thread_user_id = ? AND id >= ? ORDER BY id'
        );
        $stmt->execute([$uid, $firstId]);
        json_response(['messages' => array_map([self::class, 'shape'], $stmt->fetchAll())]);
    }

    /** Users the current account may address in the chatbox. */
    public static function recipients(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $uid = Auth::id();
        if (Auth::isAdmin()) {
            $rows = $pdo->query(
                "SELECT id, email, role, first_name, last_name FROM users WHERE status = 'active' ORDER BY role, email"
            )->fetchAll();
        } elseif (Auth::isStaff()) {
            $stmt = $pdo->prepare(
                "SELECT id, email, role, first_name, last_name FROM users
                 WHERE status = 'active' AND role IN ('admin','staff') AND id <> ?
                 ORDER BY role, email"
            );
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll();
        } else {
            json_response(['recipients' => []]);
        }
        json_response([
            'recipients' => array_map(static function (array $u): array {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                return [
                    'id'    => (int)$u['id'],
                    'email' => $u['email'],
                    'role'  => $u['role'],
                    'name'  => $name !== '' ? $name : $u['email'],
                ];
            }, $rows),
        ]);
    }

    private static function staffMayMessage(int $userId): bool
    {
        $stmt = Database::pdo()->prepare("SELECT role, status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            return false;
        }
        return in_array($row['role'], ['admin', 'staff'], true);
    }

    /** Rule-based assistant reply (no external LLM). */
    private static function autoReply(string $message): string
    {
        $phone = Settings::get('contact_phone', '') ?: '';
        $hours = Settings::get('opening_hours', '');
        $m = strtolower($message);

        $holding = "Thanks for your message! We're not able to respond immediately right now, "
            . "but we'll reply as quickly as we can.";
        if ($phone !== '') {
            $holding .= " If it's urgent, please call us on {$phone}.";
        }

        if (preg_match('/\b(track|tracking|ship|shipping|delivery|dispatch)\b/', $m)) {
            return "It looks like you're asking about shipping or tracking. Tracking links appear "
                . "on your order once it ships — check your dashboard's \"Active orders\" section. " . $holding;
        }
        if (preg_match('/\b(order|status|invoice|payment|pending|provisioning)\b/', $m)) {
            return "For order status, open your dashboard to see each order's current state "
                . "(pending → provisioning → shipped). " . $holding;
        }
        if (preg_match('/\b(hours|open|opening|when.*(open|available))\b/', $m) && $hours) {
            return "Our opening hours are {$hours}. " . $holding;
        }
        if (preg_match('/\b(price|pricing|wholesale|cost|discount)\b/', $m)) {
            return "Wholesale pricing is visible in your catalog once your account is approved. " . $holding;
        }
        return $holding;
    }

    private static function shape(array $row): array
    {
        return [
            'id'              => (int)$row['id'],
            'sender'          => $row['sender'],
            'body'            => $row['body'],
            'time'            => fmt_datetime($row['created_at']),
            'sender_user_id'  => isset($row['sender_user_id']) ? (int)$row['sender_user_id'] : null,
            'to_user_id'      => isset($row['to_user_id']) ? (int)$row['to_user_id'] : null,
        ];
    }
}
