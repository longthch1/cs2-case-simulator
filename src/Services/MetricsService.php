<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Config\Config;
use CS2\Database\Database;
use PDO;

final class MetricsService
{
    private const START_MARKER = 'app_started_at';

    public static function systemStats(): array
    {
        $storage = Config::storageDir();
        if (!is_dir($storage)) {
            @mkdir($storage, 0750, true);
        }

        $marker = rtrim($storage, '/\\') . '/' . self::START_MARKER;
        if (!is_file($marker)) {
            @file_put_contents($marker, (string)time(), LOCK_EX);
        }

        $started = (int)trim((string)@file_get_contents($marker));
        if ($started <= 0) {
            $started = time();
        }

        $uptime = max(0, time() - $started);
        $cpuLoad = function_exists('sys_getloadavg') ? (float)(sys_getloadavg()[0] ?? 0.0) : 0.0;
        $memoryMb = 0.0;
        // The original Python implementation reported process RSS via psutil.
        // Read Linux process RSS when available to keep the metric semantics close.
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/^VmRSS:\s+(\\d+)\\s+kB$/m', $status, $m)) {
            $memoryMb = round(((float)$m[1]) / 1024, 2);
        } elseif (function_exists('memory_get_usage')) {
            $memoryMb = round(memory_get_usage(true) / 1048576, 2);
        }

        $pdo = Database::connection();

        // Preserve the original Python collector's aggregate business counters.
        $cases = (int)$pdo->query('SELECT COUNT(*) FROM open_history')->fetchColumn();
        $spent = (float)$pdo->query('SELECT COALESCE(SUM(cost),0) FROM open_history')->fetchColumn();
        $won = (float)$pdo->query('SELECT COALESCE(SUM(payout),0) FROM open_history')->fetchColumn();
        $tradeups = (int)$pdo->query('SELECT COUNT(*) FROM tradeup_history')->fetchColumn();
        $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $requests = (int)$pdo->query('SELECT COUNT(*) FROM request_metrics')->fetchColumn();

        // Original Python code kept only the latest 100 response times.
        $latStmt = $pdo->query('SELECT duration_ms FROM request_metrics ORDER BY id DESC LIMIT 100');
        $latencies = array_map('floatval', $latStmt->fetchAll(PDO::FETCH_COLUMN));
        $avgLatency = $latencies
            ? round(array_sum($latencies) / count($latencies), 2)
            : 0.0;

        $statusStmt = $pdo->query('
            SELECT status_code, COUNT(*) AS total
            FROM request_metrics
            GROUP BY status_code
            ORDER BY status_code
        ');
        $statusCodes = [];
        foreach ($statusStmt->fetchAll() as $row) {
            $statusCodes[(string)$row['status_code']] = (int)$row['total'];
        }

        return [
            'uptime_seconds' => $uptime,
            'uptime_formatted' => sprintf(
                '%dh %dm %ds',
                intdiv($uptime, 3600),
                intdiv($uptime % 3600, 60),
                $uptime % 60
            ),
            'memory_usage_mb' => $memoryMb,
            'cpu_load' => round($cpuLoad, 4),
            'avg_response_time_ms' => $avgLatency,
            'total_requests' => $requests,
            'cases_opened' => $cases,
            'total_spent_usd' => round($spent, 2),
            'total_won_usd' => round($won, 2),
            'tradeups_completed' => $tradeups,
            'status_codes' => $statusCodes,
            'users_total' => $users,
        ];
    }

    public static function recordRequest(
        string $method,
        string $path,
        int $status,
        float $duration
    ): void {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('
                INSERT INTO request_metrics
                    (method, path, status_code, duration_ms, created_at)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
            ');
            $stmt->execute([$method, $path, $status, round($duration, 2)]);
        } catch (\Throwable $e) {
            // Telemetry must never turn a successful application request into an error.
        }
    }

    /*
     * Compatibility hooks for the original Python MetricsCollector API.
     * Business totals are persisted in open_history/tradeup_history, so these
     * methods intentionally remain lightweight and derive totals from MySQL.
     */
    public static function recordCaseOpen(float $cost, float $wonValue, int $count = 1): void
    {
        // Persisted totals are calculated directly from open_history.
    }

    public static function recordTradeup(): void
    {
        // Persisted total is calculated directly from tradeup_history.
    }

    public static function prometheus(): string
    {
        $stats = self::systemStats();

        $lines = [
            '# HELP cs2_uptime_seconds Process uptime in seconds',
            '# TYPE cs2_uptime_seconds gauge',
            'cs2_uptime_seconds ' . $stats['uptime_seconds'],
            '',
            '# HELP cs2_memory_usage_mb PHP application memory usage in Megabytes',
            '# TYPE cs2_memory_usage_mb gauge',
            'cs2_memory_usage_mb ' . $stats['memory_usage_mb'],
            '',
            '# HELP cs2_cpu_load_1m System 1-minute CPU load average',
            '# TYPE cs2_cpu_load_1m gauge',
            'cs2_cpu_load_1m ' . $stats['cpu_load'],
            '',
            '# HELP cs2_cases_opened_total Total number of CS2 cases opened',
            '# TYPE cs2_cases_opened_total counter',
            'cs2_cases_opened_total ' . $stats['cases_opened'],
            '',
            '# HELP cs2_money_spent_usd_total Total USD spent opening cases',
            '# TYPE cs2_money_spent_usd_total counter',
            'cs2_money_spent_usd_total ' . $stats['total_spent_usd'],
            '',
            '# HELP cs2_drops_value_usd_total Total USD value of dropped skins',
            '# TYPE cs2_drops_value_usd_total counter',
            'cs2_drops_value_usd_total ' . $stats['total_won_usd'],
            '',
            '# HELP cs2_tradeups_total Total number of trade-up contracts executed',
            '# TYPE cs2_tradeups_total counter',
            'cs2_tradeups_total ' . $stats['tradeups_completed'],
            '',
            '# HELP cs2_http_requests_total Total HTTP requests by status code',
            '# TYPE cs2_http_requests_total counter',
        ];

        foreach ($stats['status_codes'] as $status => $count) {
            $lines[] = 'cs2_http_requests_total{status="' . $status . '"} ' . $count;
        }

        $errors5xx = 0;
        foreach ($stats['status_codes'] as $status => $count) {
            if ((int)$status >= 500) {
                $errors5xx += $count;
            }
        }

        $lines = array_merge($lines, [
            '',
            '# HELP cs2_http_5xx_total Total HTTP 5xx responses',
            '# TYPE cs2_http_5xx_total counter',
            'cs2_http_5xx_total ' . $errors5xx,
            '',
            '# HELP cs2_users_total Total registered users',
            '# TYPE cs2_users_total gauge',
            'cs2_users_total ' . $stats['users_total'],
            '',
            '# HELP cs2_http_last100_avg_response_time_ms Average response time over latest 100 requests',
            '# TYPE cs2_http_last100_avg_response_time_ms gauge',
            'cs2_http_last100_avg_response_time_ms ' . $stats['avg_response_time_ms'],
            '',
        ]);

        return implode("\n", $lines);
    }
}
