<?php
/**
 * CS2 Case Opening Simulator — Auth API
 * Routes: /api/auth/*
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

$method   = getMethod();
$segments = getPathSegments(); // e.g. ['register'] or ['login']
$action   = $segments[0] ?? '';

// ── POST /api/auth/register ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'register') {
    $ip = getClientIp();
    if (isRateLimited("register_$ip", 10, 60)) jsonError('Thao tác quá nhiều lần. Vui lòng chờ 1 phút.', 429);

    $body     = getRequestBody();
    $username = trim($body['username'] ?? '');
    $email    = trim(strtolower($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (strlen($username) < 3) jsonError('Tên đăng nhập phải có ít nhất 3 ký tự.');
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
        jsonError('Email không đúng định dạng chuẩn (ví dụ: abc@def.com).');
    }
    if (strlen($password) < 6) jsonError('Mật khẩu phải có ít nhất 6 ký tự.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) jsonError('Tên đăng nhập hoặc email đã được sử dụng.');

    $hash = hashPassword($password);
    $db->prepare(
        "INSERT INTO users (username, email, password_hash, role, balance, daily_streak) VALUES (?, ?, ?, 'user', ?, 0)"
    )->execute([$username, $email, $hash, USER_STARTING_BALANCE]);
    $userId = (int)$db->lastInsertId();

    logAudit($userId, 'USER_REGISTER', $ip, "Registered: $username");

    $token = generateJWT(['sub' => $userId, 'username' => $username, 'role' => 'user']);
    jsonResponse([
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => [
            'id'               => $userId,
            'username'         => $username,
            'email'            => $email,
            'role'             => 'user',
            'balance'          => USER_STARTING_BALANCE,
            'daily_streak'     => 0,
            'last_daily_claim' => null,
            'avatar_url'       => ''
        ]
    ]);
}

// ── POST /api/auth/login ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $ip = getClientIp();
    if (isRateLimited("login_$ip", 20, 60)) jsonError('Quá nhiều lần thử đăng nhập. Vui lòng chờ 1 phút.', 429);

    $body       = getRequestBody();
    $identifier = trim($body['username_or_email'] ?? '');
    $password   = $body['password'] ?? '';

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, username, email, password_hash, role, balance, daily_streak, last_daily_claim, avatar_url
         FROM users WHERE username = ? OR email = ? LIMIT 1"
    );
    $stmt->execute([$identifier, strtolower($identifier)]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        writeLog('AUDIT', "Failed login for: $identifier from $ip");
        jsonError('Sai tên đăng nhập hoặc mật khẩu.', 401);
    }

    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    logAudit($user['id'], 'USER_LOGIN', $ip, "Login: {$user['username']}");

    $token = generateJWT(['sub' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]);
    jsonResponse([
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => [
            'id'               => $user['id'],
            'username'         => $user['username'],
            'email'            => $user['email'],
            'role'             => $user['role'],
            'balance'          => (float)$user['balance'],
            'daily_streak'     => (int)($user['daily_streak'] ?? 0),
            'last_daily_claim' => $user['last_daily_claim'],
            'avatar_url'       => $user['avatar_url'] ?? ''
        ]
    ]);
}

// ── POST /api/auth/forgot-password ───────────────────────────────────────────
if ($method === 'POST' && $action === 'forgot-password') {
    $ip = getClientIp();
    if (isRateLimited("forgot_$ip", 8, 60)) jsonError('Thao tác quá nhanh. Vui lòng thử lại sau 1 phút.', 429);

    $body     = getRequestBody();
    $username = trim($body['username'] ?? '');
    $email    = trim(strtolower($body['email'] ?? ''));
    $newPass  = $body['new_password'] ?? '';

    if (strlen($newPass) < 6) jsonError('Mật khẩu mới phải có ít nhất 6 ký tự.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND LOWER(email) = ?");
    $stmt->execute([$username, $email]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Không tìm thấy tài khoản với Tên đăng nhập và Email này.', 404);

    $hash = hashPassword($newPass);
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $user['id']]);
    logAudit($user['id'], 'PASSWORD_RESET', $ip, "Reset for: $username");

    jsonResponse(['success' => true, 'message' => 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập ngay.']);
}

// ── GET /api/auth/me ──────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'me') {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(value),0) as total_val FROM inventory WHERE user_id = ? AND is_sold = 0");
    $stmt->execute([$user['id']]);
    $inv = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) as total_opened, COALESCE(SUM(cost),0) as total_spent, COALESCE(SUM(payout),0) as total_won FROM open_history WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $hist = $stmt->fetch();

    jsonResponse([
        'user'  => $user,
        'stats' => [
            'inventory_count'    => (int)$inv['cnt'],
            'inventory_value'    => round((float)$inv['total_val'], 2),
            'total_cases_opened' => (int)$hist['total_opened'],
            'total_spent'        => round((float)$hist['total_spent'], 2),
            'total_won'          => round((float)$hist['total_won'], 2)
        ]
    ]);
}

jsonError('Route không tìm thấy.', 404);

