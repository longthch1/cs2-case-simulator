<?php
/**
 * CS2 Case Opening Simulator — HTTP Helpers & JWT Auth
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── JSON Response ─────────────────────────────────────────────────────────────
function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $code = 400): never {
    jsonResponse(['detail' => $message, 'error' => $message], $code);
}

// ── Request Parsing ───────────────────────────────────────────────────────────
function getRequestBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '127.0.0.1';
}

// ── Route Helpers ─────────────────────────────────────────────────────────────
function getApiPath(string $prefix = ''): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $uri = preg_replace('#^/api/' . preg_quote($prefix, '#') . '/?#i', '', $uri);
    return trim($uri ?? '', '/');
}

function getPathSegments(string $prefix = ''): array {
    $path = getApiPath($prefix);
    return $path === '' ? [] : explode('/', $path);
}

function getMethod(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

// ── JWT Implementation (HS256) ────────────────────────────────────────────────
function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    $pad  = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateJWT(array $payload): string {
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + (TOKEN_EXPIRE_HOURS * 3600);
    $body    = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', "$header.$body", APP_SECRET, true));
    return "$header.$body.$sig";
}

function validateJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;

    $expectedSig = base64UrlEncode(hash_hmac('sha256', "$header.$body", APP_SECRET, true));
    if (!hash_equals($expectedSig, $sig)) return null;

    $payload = json_decode(base64UrlDecode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;

    return $payload;
}

// ── Authentication ────────────────────────────────────────────────────────────
function getBearerToken(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    return null;
}

function requireAuth(): array {
    $token = getBearerToken();
    if (!$token) jsonError('Bạn chưa đăng nhập. Vui lòng đăng nhập để tiếp tục.', 401);

    $payload = validateJWT($token);
    if (!$payload || empty($payload['sub'])) jsonError('Token không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại.', 401);

    // Fetch fresh user data from DB
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, username, email, role, balance, daily_streak, last_daily_claim, avatar_url FROM users WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();

    if (!$user) jsonError('Tài khoản không tồn tại.', 401);
    return $user;
}

function optionalAuth(): ?array {
    $token = getBearerToken();
    if (!$token) return null;
    $payload = validateJWT($token);
    if (!$payload || empty($payload['sub'])) return null;

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, username, email, role, balance, daily_streak, last_daily_claim, avatar_url FROM users WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    return $stmt->fetch() ?: null;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') jsonError('Bạn không có quyền truy cập khu vực quản trị.', 403);
    return $user;
}

// ── Simple File-Based Rate Limiter ────────────────────────────────────────────
function isRateLimited(string $key, int $maxRequests, int $windowSeconds): bool {
    $dir  = sys_get_temp_dir() . '/cs2_rl';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($key) . '.json';
    $now  = time();
    $data = [];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? [];
    }

    // Remove expired timestamps
    $data = array_filter($data, fn($ts) => ($now - $ts) < $windowSeconds);
    $data = array_values($data);

    if (count($data) >= $maxRequests) return true;

    $data[] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return false;
}

