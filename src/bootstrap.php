<?php
declare(strict_types=1);

use CS2\Config\Config;

require_once __DIR__ . '/Config/config.php';
Config::loadEnv(dirname(__DIR__) . '/.env');

spl_autoload_register(function (string $class): void {
    $prefix = 'CS2\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
});

if (!is_dir(Config::storageDir())) @mkdir(Config::storageDir(), 0750, true);
if (!is_dir(Config::logDir())) @mkdir(Config::logDir(), 0750, true);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Content-Security-Policy: default-src \'self\' https: data: blob: \'unsafe-inline\' \'unsafe-eval\'; object-src \'none\'; frame-ancestors \'none\'; base-uri \'self\';');
if (Config::isProduction() && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header_remove('X-Powered-By');

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || Config::bool('APP_FORCE_SECURE_COOKIE', false);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_name('cs2_session');
session_start();

if (empty($_COOKIE['csrf_token'])) {
    $token = bin2hex(random_bytes(32));
    setcookie('csrf_token', $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_SESSION['csrf_token'] = $token;
} elseif (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = (string)$_COOKIE['csrf_token'];
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST','PUT','PATCH','DELETE'], true)) return;

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (in_array($path, ['/api/auth/login','/api/auth/register','/api/auth/forgot-password'], true)) return;

        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$provided || !$expected || !hash_equals($expected, $provided)) {
            \CS2\Utils\Logger::security('CSRF_FAILURE');
            \CS2\Utils\Response::error('CSRF validation failed', 403);
        }
    }
}
