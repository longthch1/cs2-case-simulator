<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;
use CS2\Config\Config;

final class MetricsService
{
    public static function systemStats(): array
    {
        $storage = Config::storageDir();
        if (!is_dir($storage)) @mkdir($storage, 0750, true);
        $marker = $storage . '/app_started_at';
        if (!is_file($marker)) @file_put_contents($marker, (string)time());
        $started = is_file($marker) ? (int)trim((string)@file_get_contents($marker)) : time();
        $uptime = max(0, time() - $started);

        $cpuLoad = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;
        $memoryMb = 0.0;
        if (function_exists('memory_get_usage')) $memoryMb = round(memory_get_usage(true)/1048576,2);

        $pdo = Database::connection();
        $totalRequests = (int)$pdo->query('SELECT COUNT(*) FROM request_metrics')->fetchColumn();
        $cases = (int)$pdo->query('SELECT COUNT(*) FROM open_history')->fetchColumn();
        $spent = (float)$pdo->query('SELECT COALESCE(SUM(cost),0) FROM open_history')->fetchColumn();
        $won = (float)$pdo->query('SELECT COALESCE(SUM(payout),0) FROM open_history')->fetchColumn();
        $tradeups = (int)$pdo->query('SELECT COUNT(*) FROM tradeup_history')->fetchColumn();

        $lat = (float)$pdo->query('SELECT COALESCE(AVG(duration_ms),0) FROM request_metrics WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE')->fetchColumn();

        return [
            'uptime_seconds'=>$uptime,
            'uptime_formatted'=>sprintf('%dh %dm %ds', intdiv($uptime,3600), intdiv($uptime%3600,60), $uptime%60),
            'memory_usage_mb'=>$memoryMb,
            'cpu_load'=>$cpuLoad,
            'avg_response_time_ms'=>round($lat,2),
            'total_requests'=>$totalRequests,
            'cases_opened'=>$cases,
            'total_spent_usd'=>round($spent,2),
            'total_won_usd'=>round($won,2),
            'tradeups_completed'=>$tradeups,
        ];
    }

    public static function recordRequest(string $method,string $path,int $status,float $duration): void
    {
        $pdo = Database::connection();
        $stmt=$pdo->prepare('INSERT INTO request_metrics (method,path,status_code,duration_ms,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
        $stmt->execute([$method,$path,$status,round($duration,2)]);
    }

    public static function prometheus(): string
    {
        $pdo=Database::connection();
        $requests=(int)$pdo->query('SELECT COUNT(*) FROM request_metrics')->fetchColumn();
        $errors=(int)$pdo->query('SELECT COUNT(*) FROM request_metrics WHERE status_code >= 500')->fetchColumn();
        $cases=(int)$pdo->query('SELECT COUNT(*) FROM open_history')->fetchColumn();
        $tradeups=(int)$pdo->query('SELECT COUNT(*) FROM tradeup_history')->fetchColumn();
        $users=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return implode("\n",[
            '# HELP cs2_http_requests_total Total application HTTP requests',
            '# TYPE cs2_http_requests_total counter',
            "cs2_http_requests_total {$requests}",
            '# HELP cs2_http_5xx_total Total HTTP 5xx responses',
            '# TYPE cs2_http_5xx_total counter',
            "cs2_http_5xx_total {$errors}",
            '# HELP cs2_cases_opened_total Total case openings',
            '# TYPE cs2_cases_opened_total counter',
            "cs2_cases_opened_total {$cases}",
            '# HELP cs2_tradeups_total Total trade-up contracts',
            '# TYPE cs2_tradeups_total counter',
            "cs2_tradeups_total {$tradeups}",
            '# HELP cs2_users_total Total registered users',
            '# TYPE cs2_users_total gauge',
            "cs2_users_total {$users}",
            ''
        ]);
    }
}
