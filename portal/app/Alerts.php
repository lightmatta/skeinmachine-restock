<?php
declare(strict_types=1);

namespace App;

/**
 * Realtime chat + admin-event alerts for logged-in users.
 * Snapshot never marks messages as read — opening the chatbox does that.
 */
class Alerts
{
    public const EVENT_TYPES = ['order', 'application', 'order_cancelled', 'order_completed', 'work_order_stalled'];

    public static function firstWords(string $body, int $n = 5): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $words = preg_split('/\s+/u', $body) ?: [];
        return implode(' ', array_slice($words, 0, $n));
    }

    public static function log(?int $userId, string $type, string $description): void
    {
        Database::pdo()->prepare('INSERT INTO activity (user_id, type, description) VALUES (?,?,?)')
            ->execute([$userId, $type, $description]);
    }

    /**
     * Unread incoming messages (and, for admins, client-event activity)
     * since the given ids. Does not mutate read flags.
     *
     * @return array{
     *   messages: list<array<string,mixed>>,
     *   unread: int,
     *   im_browser_notifications: bool,
     *   admin_event_alerts: bool,
     *   events: list<array<string,mixed>>
     * }
     */
    public static function snapshot(int $afterMsg = 0, int $afterEvent = 0): array
    {
        $uid = Auth::id();
        $pdo = Database::pdo();
        $messages = [];
        $unread = 0;

        if (Auth::isAdmin()) {
            $stmt = $pdo->prepare(
                "SELECT m.id, m.sender, m.body, m.created_at, m.sender_user_id, m.to_user_id,
                        u.first_name, u.last_name, u.email
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_user_id
                 WHERE m.id > ? AND m.sender IN ('client','staff')
                   AND (m.sender_user_id IS NULL OR m.sender_user_id <> ?)
                 ORDER BY m.id DESC LIMIT 25"
            );
            $stmt->execute([$afterMsg, $uid]);
            $messages = $stmt->fetchAll();
            $unread = (int)$pdo->query(
                "SELECT COUNT(*) FROM messages WHERE sender IN ('client','staff') AND read_by_admin = 0"
            )->fetchColumn();
        } else {
            $mine = Auth::isStaff() ? 'staff' : 'client';
            $stmt = $pdo->prepare(
                "SELECT m.id, m.sender, m.body, m.created_at, m.sender_user_id, m.to_user_id,
                        u.first_name, u.last_name, u.email
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_user_id
                 WHERE m.id > ?
                   AND (m.sender_user_id IS NULL OR m.sender_user_id <> ?)
                   AND (m.thread_user_id = ? OR m.to_user_id = ?)
                   AND m.sender <> ?
                 ORDER BY m.id DESC LIMIT 25"
            );
            $stmt->execute([$afterMsg, $uid, $uid, $uid, $mine]);
            $messages = $stmt->fetchAll();
            $unreadStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM messages
                 WHERE (thread_user_id = ? OR to_user_id = ?)
                   AND read_by_client = 0
                   AND sender <> ?
                   AND (sender_user_id IS NULL OR sender_user_id <> ?)"
            );
            $unreadStmt->execute([$uid, $uid, $mine, $uid]);
            $unread = (int)$unreadStmt->fetchColumn();
        }

        $shaped = [];
        foreach ($messages as $row) {
            $shaped[] = self::shapeMessage($row);
        }

        $adminAlerts = Auth::isAdmin() && Settings::adminEventAlerts();
        $events = [];
        if ($adminAlerts) {
            $placeholders = implode(',', array_fill(0, count(self::EVENT_TYPES), '?'));
            $ev = $pdo->prepare(
                "SELECT id, type, description, created_at FROM activity
                 WHERE type IN ({$placeholders}) AND id > ?
                 ORDER BY id ASC LIMIT 25"
            );
            $ev->execute(array_merge(self::EVENT_TYPES, [$afterEvent]));
            foreach ($ev->fetchAll() as $e) {
                $events[] = [
                    'id'          => (int)$e['id'],
                    'type'        => $e['type'],
                    'description' => (string)$e['description'],
                    'time'        => fmt_datetime($e['created_at']),
                    'title'       => self::eventTitle((string)$e['type']),
                ];
            }
        }

        return [
            'messages'                 => $shaped,
            'unread'                   => $unread,
            'im_browser_notifications' => Settings::imBrowserNotifications(),
            'admin_event_alerts'       => $adminAlerts,
            'events'                   => $events,
        ];
    }

    public static function eventTitle(string $type): string
    {
        return match ($type) {
            'order'               => 'New client order',
            'application'         => 'New wholesale application',
            'order_cancelled'     => 'Order cancelled',
            'order_completed'     => 'Order completed',
            'work_order_stalled'  => 'Work order stalled',
            default            => 'Client event',
        };
    }

    /** @param array<string,mixed> $row */
    private static function shapeMessage(array $row): array
    {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($name === '') {
            $name = match ((string)$row['sender']) {
                'admin', 'system' => 'Support',
                'staff'           => 'Staff',
                'client'          => (string)($row['email'] ?? 'Customer'),
                default           => 'HouseDye',
            };
        }
        $body = (string)$row['body'];
        return [
            'id'          => (int)$row['id'],
            'sender'      => $row['sender'],
            'sender_name' => $name,
            'body'        => $body,
            'preview'     => self::firstWords($body, 5),
            'time'        => fmt_datetime($row['created_at'] ?? null),
        ];
    }
}
