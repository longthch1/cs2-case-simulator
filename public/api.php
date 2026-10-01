<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CS2\Auth\AuthService;
use CS2\Config\Config;
use CS2\Database\Database;
use CS2\Middleware\AdminMiddleware;
use CS2\Middleware\AuthMiddleware;
use CS2\Services\AdminService;
use CS2\Services\CaseService;
use CS2\Services\DailyService;
use CS2\Services\InventoryService;
use CS2\Services\MetricsService;
use CS2\Services\TradeUpService;
use CS2\Services\WalletService;
use CS2\Utils\Logger;
use CS2\Utils\Request;
use CS2\Utils\Response;

$started = microtime(true);
$method = Request::method();
$path = Request::path();

register_shutdown_function(function () use ($started, $method, $path): void {
    try {
        MetricsService::recordRequest($method, $path, http_response_code() ?: 200, (microtime(true) - $started) * 1000);
    } catch (Throwable $ignored) {
        // Metrics persistence must never break the response.
    }
});

set_exception_handler(function (Throwable $e): void {
    Logger::error($e->getMessage());
    if (Config::bool('APP_DEBUG', false)) {
        Response::json(['detail'=>$e->getMessage()], 500);
    }
    Response::error('An internal server error occurred.', 500);
});

try {
    require_csrf();

    if ($method === 'GET' && $path === '/metrics') {
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo MetricsService::prometheus();
        exit;
    }

    if ($method === 'GET' && $path === '/api/health') {
        $db = 'ok';
        try { Database::connection()->query('SELECT 1')->fetchColumn(); }
        catch (Throwable $e) { $db = 'unhealthy'; Logger::error('Health DB check failed: '.$e->getMessage()); }

        $stats = MetricsService::systemStats();
        Response::json([
            'status' => $db === 'ok' ? 'healthy' : 'degraded',
            'service' => 'CS2 Case Simulator Platform',
            'version' => '2.0.0-php',
            'environment' => Config::env('APP_ENV','production'),
            'database' => $db,
            'disk_free_gb' => round((float)@disk_free_space(Config::storageDir()) / 1073741824, 2),
            'uptime' => $stats['uptime_formatted'],
            'memory_mb' => $stats['memory_usage_mb'],
            'avg_latency_ms' => $stats['avg_response_time_ms']
        ], $db === 'ok' ? 200 : 503);
    }

    // Authentication
    if ($method === 'POST' && $path === '/api/auth/login') {
        Response::json(AuthService::login(Request::body()));
    }
    if ($method === 'POST' && $path === '/api/auth/register') {
        Response::json(AuthService::register(Request::body()));
    }
    if ($method === 'POST' && $path === '/api/auth/forgot-password') {
        Response::json(AuthService::forgotPassword(Request::body()));
    }
    if ($method === 'POST' && $path === '/api/auth/logout') {
        AuthService::logout();
        Response::json(['success'=>true]);
    }
    if ($method === 'GET' && $path === '/api/auth/me') {
        Response::json(AuthService::me());
    }

    // Public cases
    if ($method === 'GET' && $path === '/api/cases') {
        Response::json(CaseService::listCases());
    }
    if ($method === 'POST' && $path === '/api/cases/verify-roll') {
        Response::json(CaseService::verifyRoll(Request::body()));
    }

    if ($method === 'GET' && preg_match('#^/api/cases/([^/]+)$#', $path, $m)) {
        Response::json(CaseService::detail(rawurldecode($m[1])));
    }

    $user = null;

    $needsUser = (
        str_starts_with($path, '/api/daily/') ||
        str_starts_with($path, '/api/inventory') ||
        ($path === '/api/wallet/redeem-code' ||
         $path === '/api/wallet/deposit' ||
         $path === '/api/wallet/transactions') ||
        $path === '/api/tradeup' ||
        (str_starts_with($path, '/api/cases/') && str_ends_with($path, '/open')) ||
        str_starts_with($path, '/api/admin/')
    );

    if ($needsUser) {
        $user = AuthMiddleware::user();
    }

    // Daily
    if ($method === 'GET' && $path === '/api/daily/status') {
        Response::json(DailyService::status($user));
    }
    if ($method === 'POST' && $path === '/api/daily/claim') {
        Response::json(DailyService::claim($user));
    }

    // Case opening
    if ($method === 'POST' && preg_match('#^/api/cases/([^/]+)/open$#', $path, $m)) {
        Response::json(CaseService::open(rawurldecode($m[1]), Request::body(), $user));
    }

    // Inventory
    if ($method === 'GET' && $path === '/api/inventory') {
        Response::json(InventoryService::list($user));
    }
    if ($method === 'POST' && preg_match('#^/api/inventory/(\d+)/sell$#', $path, $m)) {
        Response::json(InventoryService::sell((int)$m[1], $user));
    }
    if ($method === 'POST' && $path === '/api/inventory/sell-all') {
        Response::json(InventoryService::sellAll($user));
    }

    // Trade-up
    if ($method === 'POST' && $path === '/api/tradeup') {
        Response::json(TradeUpService::execute(Request::body(), $user));
    }

    // Wallet
    if ($method === 'GET' && $path === '/api/wallet/active-code') {
        Response::json(WalletService::activeCode(AuthService::currentUser()));
    }
    if ($method === 'POST' && $path === '/api/wallet/redeem-code') {
        Response::json(WalletService::redeem(Request::body(), $user));
    }
    if ($method === 'POST' && $path === '/api/wallet/deposit') {
        Response::json(WalletService::deposit(Request::body(), $user));
    }
    if ($method === 'GET' && $path === '/api/wallet/transactions') {
        Response::json(WalletService::transactions($user));
    }

    // Admin
    if (str_starts_with($path, '/api/admin/')) {
        $admin = AdminMiddleware::user($user);

        if ($method === 'GET' && $path === '/api/admin/overview') {
            Response::json(AdminService::overview());
        }
        if ($method === 'GET' && $path === '/api/admin/users') {
            Response::json(AdminService::users());
        }
        if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/balance$#', $path, $m)) {
            Response::json(AdminService::adjustBalance((int)$m[1], Request::body(), $admin));
        }
        if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/role$#', $path, $m)) {
            Response::json(AdminService::updateRole((int)$m[1], Request::body(), $admin));
        }
        if ($method === 'GET' && preg_match('#^/api/admin/users/(\d+)/inventory$#', $path, $m)) {
            Response::json(AdminService::userInventory((int)$m[1]));
        }
        if ($method === 'GET' && $path === '/api/admin/cases') {
            Response::json(AdminService::cases());
        }
        if ($method === 'POST' && preg_match('#^/api/admin/cases/([^/]+)$#', $path, $m)) {
            Response::json(AdminService::updateCase(rawurldecode($m[1]), Request::body()));
        }
        if ($method === 'GET' && $path === '/api/admin/giftcode') {
            Response::json(AdminService::giftcode());
        }
        if ($method === 'POST' && $path === '/api/admin/giftcode/generate') {
            Response::json(AdminService::generateGiftcode($admin));
        }
        if ($method === 'POST' && $path === '/api/admin/giftcode/reward') {
            Response::json(AdminService::setReward(Request::body(), $admin));
        }
        if ($method === 'GET' && $path === '/api/admin/audit') {
            Response::json(AdminService::audit(Request::intQuery('limit',100,1,500)));
        }
        if ($method === 'GET' && $path === '/api/admin/logs') {
            Response::json(AdminService::logs((string)Request::query('log_type','app'), Request::intQuery('lines',150,1,500)));
        }
    }

    Response::error('Not found', 404);
} finally {
    try {
        if (isset($started)) {
            MetricsService::recordRequest($method, $path, http_response_code() ?: 200, (microtime(true)-$started)*1000);
        }
    } catch (Throwable) {
        // Never break an API response because metrics persistence failed.
    }
}
