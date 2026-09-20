<?php
declare(strict_types=1);

namespace App\Nlp;

use App\Database;
use App\Settings;
use PDO;

/**
 * Native, rule-based Natural Language Processing for the analytics assistant.
 *
 * This is NOT a large language model and calls no external API. It parses a
 * human question with layered regular expressions and keyword rules to extract:
 *   - an intent / metric (top products, top bundles, client frequency,
 *     revenue, order count),
 *   - a date range (rich set of presets + explicit ranges),
 *   - an optional client filter (by name or email),
 * then runs a parameterised SQL query and returns a tabulated result plus a
 * chart series and a plain-English interpretation of what it understood.
 */
class QueryEngine
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function run(string $query): array
    {
        $q = strtolower(trim($query));
        $metric = $this->detectMetric($q);
        $range  = $this->detectRange($q);
        $client = $this->detectClient($q);

        $result = $this->execute($metric, $range, $client);

        $interpretation = sprintf(
            'Interpreted as: %s, %s%s.',
            $result['metric_label'],
            $range['label'],
            $client ? ' for ' . $client['label'] : ' across all clients'
        );

        return [
            'interpretation' => $interpretation,
            'metric'         => $metric,
            'range'          => ['start' => $range['start'], 'end' => $range['end'], 'label' => $range['label']],
            'columns'        => $result['columns'],
            'rows'           => $result['rows'],
            'chart'          => $result['chart'],
            'summary'        => $result['summary'],
        ];
    }

    /* ----------------- Intent detection ----------------- */

    private function detectMetric(string $q): string
    {
        if (preg_match('/\bbundle/', $q) && preg_match('/\b(top|popular|best|most|ordered|selling)\b/', $q)) {
            return 'top_bundles';
        }
        if (preg_match('/\b(product|item|yarn|sku)/', $q) && preg_match('/\b(top|popular|best|most|ordered|selling)\b/', $q)) {
            return 'top_products';
        }
        if (preg_match('/\b(client|customer|account|who)\b/', $q) &&
            preg_match('/\b(frequen|most|active|often|loyal|order count|orders)\b/', $q)) {
            return 'client_frequency';
        }
        if (preg_match('/\b(revenue|sales|income|turnover|spend|spent|value|money|\$)/', $q)) {
            return 'revenue';
        }
        if (preg_match('/\b(how many orders|number of orders|order count|orders placed|count.*orders)\b/', $q)) {
            return 'order_count';
        }
        // Fallbacks based on a single keyword.
        if (preg_match('/\bbundle/', $q)) return 'top_bundles';
        if (preg_match('/\b(product|item|yarn)/', $q)) return 'top_products';
        if (preg_match('/\b(client|customer)/', $q)) return 'client_frequency';
        return 'revenue';
    }

    /* ----------------- Date range detection ----------------- */

    private function detectRange(string $q): array
    {
        $today = new \DateTimeImmutable('today');
        $fmt = 'Y-m-d';
        $mk = fn(\DateTimeInterface $a, \DateTimeInterface $b, string $label) =>
            ['start' => $a->format($fmt), 'end' => $b->format($fmt), 'label' => $label];

        // Explicit "last N days/weeks/months"
        if (preg_match('/\blast\s+(\d{1,4})\s+(day|week|month|year)s?\b/', $q, $m)) {
            $n = (int)$m[1];
            $unit = ['day' => 'days', 'week' => 'weeks', 'month' => 'months', 'year' => 'years'][$m[2]];
            $start = $today->modify("-{$n} {$unit}");
            return $mk($start, $today, "the last {$n} {$m[2]}" . ($n === 1 ? '' : 's'));
        }
        // Explicit "between X and Y" / "from X to Y"
        if (preg_match('/\b(?:between|from)\s+(.+?)\s+(?:and|to|-|until)\s+(.+?)(?:\.|$)/', $q, $m)) {
            $a = strtotime($m[1]);
            $b = strtotime($m[2]);
            if ($a && $b) {
                return $mk((new \DateTimeImmutable())->setTimestamp(min($a, $b)),
                          (new \DateTimeImmutable())->setTimestamp(max($a, $b)),
                          'from ' . date($fmt, min($a, $b)) . ' to ' . date($fmt, max($a, $b)));
            }
        }

        $presets = [
            'today'            => fn() => $mk($today, $today, 'today'),
            'yesterday'        => fn() => $mk($today->modify('-1 day'), $today->modify('-1 day'), 'yesterday'),
            'this week'        => fn() => $mk($today->modify('monday this week'), $today, 'this week'),
            'last week'        => fn() => $mk($today->modify('monday last week'), $today->modify('sunday last week'), 'last week'),
            'this month'       => fn() => $mk($today->modify('first day of this month'), $today, 'this month'),
            'last month'       => fn() => $mk($today->modify('first day of last month'), $today->modify('last day of last month'), 'last month'),
            'this year'        => fn() => $mk($today->modify('first day of january this year'), $today, 'this year'),
            'last year'        => fn() => $mk($today->modify('first day of january last year'), $today->modify('last day of december last year'), 'last year'),
            'year to date'     => fn() => $mk($today->modify('first day of january this year'), $today, 'year to date'),
            'ytd'              => fn() => $mk($today->modify('first day of january this year'), $today, 'year to date'),
            'all time'         => fn() => $mk(new \DateTimeImmutable('2000-01-01'), $today, 'all time'),
        ];
        foreach ($presets as $needle => $fn) {
            if (str_contains($q, $needle)) {
                return $fn();
            }
        }

        // Financial year (configurable start month, default July).
        if (preg_match('/\b(this |last |current |previous )?(financial|fiscal) year\b/', $q, $m)) {
            $startMonth = (int)(Settings::get('fy_start_month', '7') ?: 7);
            $prev = trim($m[1] ?? '');
            return $this->financialYear($today, $startMonth, in_array($prev, ['last', 'previous'], true));
        }

        // Default: last 30 days.
        return $mk($today->modify('-30 days'), $today, 'the last 30 days (default)');
    }

    private function financialYear(\DateTimeImmutable $today, int $startMonth, bool $previous): array
    {
        $year = (int)$today->format('Y');
        $month = (int)$today->format('n');
        $fyStartYear = ($month >= $startMonth) ? $year : $year - 1;
        if ($previous) {
            $fyStartYear -= 1;
        }
        $start = (new \DateTimeImmutable())->setDate($fyStartYear, $startMonth, 1)->setTime(0, 0);
        $end = $start->modify('+1 year')->modify('-1 day');
        $label = ($previous ? 'the previous' : 'this') . ' financial year (' . $start->format('M Y') . ' – ' . $end->format('M Y') . ')';
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'label' => $label];
    }

    /* ----------------- Client detection ----------------- */

    private function detectClient(string $q): ?array
    {
        if (preg_match('/\b(all|every|each)\b.*\bclient/', $q)) {
            return null;
        }
        // Try email match.
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/', $q, $m)) {
            $u = $this->findUser($m[0]);
            if ($u) return $u;
        }
        // Try "for/by <name>" capture, then match against user names.
        if (preg_match('/\b(?:for|by|from|of)\s+(?:client\s+|customer\s+)?([a-z][a-z\s\-\']{1,40})/', $q, $m)) {
            $candidate = trim($m[1]);
            // Strip trailing range words that may have been captured.
            $candidate = preg_replace('/\b(this|last|the|in|over|during|for|financial|fiscal|year|month|week|day|days|today).*$/', '', $candidate);
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $u = $this->findUser($candidate);
                if ($u) return $u;
            }
        }
        return null;
    }

    private function findUser(string $needle): ?array
    {
        $needle = trim($needle);
        if ($needle === '') return null;
        $like = '%' . $needle . '%';
        $stmt = $this->pdo->prepare(
            "SELECT id, email, first_name, last_name FROM users
             WHERE email = ? OR (first_name || ' ' || last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ?
             LIMIT 1"
        );
        $stmt->execute([strtolower($needle), $like, $like, $like]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['email'];
        return ['id' => (int)$row['id'], 'label' => $name . ' (' . $row['email'] . ')'];
    }

    /* ----------------- Execution ----------------- */

    private function execute(string $metric, array $range, ?array $client): array
    {
        $where = "date(o.created_at) BETWEEN ? AND ? AND o.status <> 'cancelled'";
        $params = [$range['start'], $range['end']];
        if ($client) {
            $where .= ' AND o.user_id = ?';
            $params[] = $client['id'];
        }

        switch ($metric) {
            case 'top_products':
                $sql = "SELECT COALESCE(p.title, oi.title) AS label,
                               SUM(oi.qty) AS units,
                               SUM(oi.line_total_cents) AS revenue_cents
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        LEFT JOIN products p ON p.id = oi.product_id
                        WHERE {$where}
                        GROUP BY COALESCE(p.id, oi.title)
                        ORDER BY units DESC LIMIT 25";
                $rows = $this->query($sql, $params);
                foreach ($rows as &$r) { $r['revenue'] = money((int)$r['revenue_cents']); unset($r['revenue_cents']); }
                unset($r);
                return [
                    'metric_label' => 'most-ordered products',
                    'columns' => ['label' => 'Product', 'units' => 'Units', 'revenue' => 'Revenue'],
                    'rows' => $rows,
                    'chart' => $this->bar($rows, 'label', 'units', 'Units ordered'),
                    'summary' => count($rows) . ' products ordered in range.',
                ];

            case 'top_bundles':
                $sql = "SELECT b.title AS label, SUM(oi.qty) AS units, SUM(oi.line_total_cents) AS revenue_cents
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        JOIN bundles b ON b.id = oi.bundle_id
                        WHERE {$where} AND oi.bundle_id IS NOT NULL
                        GROUP BY oi.bundle_id ORDER BY units DESC LIMIT 25";
                $rows = $this->query($sql, $params);
                foreach ($rows as &$r) { $r['revenue'] = money((int)$r['revenue_cents']); unset($r['revenue_cents']); }
                unset($r);
                return [
                    'metric_label' => 'most-ordered bundles',
                    'columns' => ['label' => 'Bundle', 'units' => 'Units', 'revenue' => 'Revenue'],
                    'rows' => $rows,
                    'chart' => $this->bar($rows, 'label', 'units', 'Bundle units ordered'),
                    'summary' => count($rows) . ' bundles ordered in range.',
                ];

            case 'client_frequency':
                $sql = "SELECT (u.first_name || ' ' || u.last_name) AS label, u.email AS email,
                               COUNT(o.id) AS orders, SUM(o.total_cents) AS spend_cents
                        FROM orders o JOIN users u ON u.id = o.user_id
                        WHERE {$where}
                        GROUP BY o.user_id ORDER BY orders DESC LIMIT 25";
                $rows = $this->query($sql, $params);
                foreach ($rows as &$r) { $r['spend'] = money((int)$r['spend_cents']); unset($r['spend_cents']); }
                unset($r);
                return [
                    'metric_label' => 'client order frequency',
                    'columns' => ['label' => 'Client', 'email' => 'Email', 'orders' => 'Orders', 'spend' => 'Spend'],
                    'rows' => $rows,
                    'chart' => $this->bar($rows, 'label', 'orders', 'Orders per client'),
                    'summary' => count($rows) . ' clients ordered in range.',
                ];

            case 'order_count':
                $sql = "SELECT date(o.created_at) AS label, COUNT(*) AS orders, SUM(o.total_cents) AS revenue_cents
                        FROM orders o WHERE {$where} GROUP BY date(o.created_at) ORDER BY label";
                $rows = $this->query($sql, $params);
                $total = array_sum(array_map(fn($r) => (int)$r['orders'], $rows));
                foreach ($rows as &$r) { $r['revenue'] = money((int)$r['revenue_cents']); unset($r['revenue_cents']); }
                unset($r);
                return [
                    'metric_label' => 'order count over time',
                    'columns' => ['label' => 'Date', 'orders' => 'Orders', 'revenue' => 'Revenue'],
                    'rows' => $rows,
                    'chart' => $this->bar($rows, 'label', 'orders', 'Orders per day'),
                    'summary' => "{$total} orders placed in range.",
                ];

            case 'revenue':
            default:
                $sql = "SELECT date(o.created_at) AS label, SUM(o.total_cents) AS revenue_cents, COUNT(*) AS orders
                        FROM orders o WHERE {$where} GROUP BY date(o.created_at) ORDER BY label";
                $rows = $this->query($sql, $params);
                $total = array_sum(array_map(fn($r) => (int)$r['revenue_cents'], $rows));
                $display = array_map(fn($r) => [
                    'label' => $r['label'], 'revenue' => money((int)$r['revenue_cents']), 'orders' => $r['orders'],
                ], $rows);
                return [
                    'metric_label' => 'revenue over time',
                    'columns' => ['label' => 'Date', 'revenue' => 'Revenue', 'orders' => 'Orders'],
                    'rows' => $display,
                    'chart' => $this->bar(array_map(fn($r) => [
                        'label' => $r['label'], 'value' => round(((int)$r['revenue_cents']) / 100, 2),
                    ], $rows), 'label', 'value', 'Revenue ($) per day'),
                    'summary' => 'Total revenue in range: ' . money($total) . '.',
                ];
        }
    }

    private function query(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Build a simple bar-chart series from rows. */
    private function bar(array $rows, string $labelKey, string $valueKey, string $title): array
    {
        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string)($r[$labelKey] ?? '');
            $values[] = (float)($r[$valueKey] ?? 0);
        }
        return ['title' => $title, 'labels' => array_slice($labels, 0, 15), 'values' => array_slice($values, 0, 15)];
    }
}
