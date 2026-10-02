<?php
/**
 * CS2 Case Opening Simulator — Application Configuration
 * PHP 8.0+ | Apache | MySQL 8.0
 */

// ── Load .env if present ──────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (getenv($k) === false) {
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }
}

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT')     ?: '3306');
define('DB_NAME',     getenv('DB_NAME')     ?: 'cs2_simulator');
define('DB_USER',     getenv('DB_USER')     ?: 'cs2_user');
define('DB_PASS',     getenv('DB_PASS')     ?: 'cs2_password');
define('DB_CHARSET',  'utf8mb4');

// ── App Security ──────────────────────────────────────────────────────────────
define('APP_SECRET',  getenv('APP_SECRET')  ?: 'cs2-simulator-secret-key-2024-please-change-in-production');
define('APP_ENV',     getenv('APP_ENV')     ?: 'development');
define('TOKEN_EXPIRE_HOURS', 72);

// ── Admin Account ─────────────────────────────────────────────────────────────
define('ADMIN_USERNAME',         'admin');
define('ADMIN_PASSWORD',         'admin');
define('ADMIN_EMAIL',            'admin@cs2sim.local');
define('ADMIN_STARTING_BALANCE', 999999.00);
define('USER_STARTING_BALANCE',  0.00);

// ── Paths ─────────────────────────────────────────────────────────────────────
define('BASE_DIR',    dirname(__DIR__));
define('LOG_DIR',     BASE_DIR . '/logs');

// ── CORS Headers ──────────────────────────────────────────────────────────────
function setCorsHeaders(): void {
    $allowedOrigins = ['http://localhost', 'http://127.0.0.1', 'http://localhost:80', 'http://localhost:8080'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (APP_ENV === 'development') {
        // Allow all origins in development
        header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        header('Vary: Origin');
    } elseif (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Content-Type: application/json; charset=utf-8');

    // Handle preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Logging ───────────────────────────────────────────────────────────────────
function writeLog(string $level, string $message): void {
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $line      = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_DIR . '/app.log',   $line, FILE_APPEND | LOCK_EX);
    if ($level === 'AUDIT') {
        file_put_contents(LOG_DIR . '/audit.log', $line, FILE_APPEND | LOCK_EX);
    }
}

