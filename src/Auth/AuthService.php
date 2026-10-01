<?php
declare(strict_types=1);

namespace CS2\Auth;

use CS2\Config\Config;
use CS2\Database\Database;
use CS2\Services\RateLimiter;
use CS2\Utils\Logger;
use CS2\Utils\Request;
use CS2\Utils\Response;
use PDO;

final class AuthService
{
    public static function register(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            Response::error('Invalid username. Use 3-32 letters, numbers, underscore, dot or hyphen.', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            Response::error('Invalid email address.', 400);
        }
        if (strlen($password) < 6 || strlen($password) > 128) {
            Response::error('Password must be between 6 and 128 characters.', 400);
        }

        $ip = Request::clientIp();
        if (!RateLimiter::allow('register:' . $ip, 10, 60)) {
            Logger::security('RATE_LIMIT', ['endpoint' => '/api/auth/register']);
            Response::error('Too many registration attempts. Please try again later.', 429);
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            Response::error('Username or email is already in use.', 409);
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        Database::transaction(function (PDO $pdo) use ($username, $email, $hash, $ip, &$userId): void {
            $stmt = $pdo->prepare('
                INSERT INTO users (username, email, password_hash, role, balance, daily_streak, last_daily_claim, avatar_url)
                VALUES (?, ?, ?, "user", ?, 0, NULL, "")
            ');
            $stmt->execute([$username, $email, $hash, Config::startingBalance()]);
            $userId = (int)$pdo->lastInsertId();

            $audit = $pdo->prepare('
                INSERT INTO audit_logs (user_id, action, ip_address, user_agent, details)
                VALUES (?, "USER_REGISTER", ?, ?, ?)
            ');
            $audit->execute([$userId, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '', "Registered account: {$username}"]);
        });

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        Logger::audit("USER_REGISTER user={$username} id={$userId} ip={$ip}");
        Logger::security('USER_REGISTER', ['user_id' => $userId, 'username' => $username]);

        return self::userResponseById($userId, true);
    }

    public static function login(array $input): array
    {
        $identifier = trim((string)($input['username_or_email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            Response::error('Username/email and password are required.', 400);
        }

        $ip = Request::clientIp();
        if (!RateLimiter::allow('login:' . $ip, 20, 60)) {
            Logger::security('RATE_LIMIT', ['endpoint' => '/api/auth/login']);
            Response::error('Too many login attempts. Please try again later.', 429);
        }

        $stmt = Database::connection()->prepare('
            SELECT id, username, email, password_hash, role, balance, daily_streak, last_daily_claim, avatar_url, created_at
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ');
        $stmt->execute([$identifier, strtolower($identifier)]);
        $user = $stmt->fetch();

        $valid = false;
        if ($user) {
            $valid = password_verify($password, $user['password_hash'] ?? '');
            if (!$valid && !empty($user['legacy_password_hash']) && !empty($user['legacy_salt'])) {
                $legacy = hash_pbkdf2('sha256', $password, hex2bin((string)$user['legacy_salt']), 100000, 0, true);
                $legacyHex = bin2hex($legacy);
                $valid = hash_equals((string)$user['legacy_password_hash'], $legacyHex);
                if ($valid) {
                    $newHash = password_hash($password, PASSWORD_ARGON2ID);
                    if ($newHash !== false) {
                        $upgrade = Database::connection()->prepare('UPDATE users SET password_hash = ?, legacy_password_hash = NULL, legacy_salt = NULL WHERE id = ?');
                        $upgrade->execute([$newHash, $user['id']]);
                    }
                }
            }
        }
        if (!$valid) {
            Logger::audit("LOGIN_FAILED identifier=" . substr($identifier, 0, 80) . " ip={$ip}");
            Logger::security('LOGIN_FAILED', ['identifier' => substr($identifier, 0, 80)]);
            Response::error('Sai tên đăng nhập hoặc mật khẩu', 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            if ($newHash) {
                $upd = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([$newHash, $user['id']]);
            }
        }

        $upd = Database::connection()->prepare('UPDATE users SET last_login = UTC_TIMESTAMP() WHERE id = ?');
        $upd->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['authenticated_at'] = time();

        Logger::audit("LOGIN_SUCCESS user={$user['username']} id={$user['id']} ip={$ip}");
        Logger::security('LOGIN_SUCCESS', ['user_id' => (int)$user['id'], 'username' => $user['username']]);

        return self::userResponseById((int)$user['id'], true);
    }

    public static function logout(): void
    {
        $user = self::currentUser();
        if ($user) {
            Logger::audit("LOGOUT user={$user['username']} id={$user['id']} ip=" . Request::clientIp());
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function currentUser(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id || !is_numeric($id)) {
            return null;
        }

        $stmt = Database::connection()->prepare('
            SELECT id, username, email, role, balance, daily_streak, last_daily_claim, avatar_url, created_at, last_login
            FROM users WHERE id = ? LIMIT 1
        ');
        $stmt->execute([(int)$id]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }

        $user['id'] = (int)$user['id'];
        $user['balance'] = (float)$user['balance'];
        $user['daily_streak'] = (int)$user['daily_streak'];
        return $user;
    }

    public static function me(): array
    {
        $user = self::currentUser();
        if (!$user) {
            Response::error('Not authenticated', 401);
        }

        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT COUNT(*) count, COALESCE(SUM(value),0) total_val FROM inventory WHERE user_id = ? AND is_sold = 0');
        $s->execute([$user['id']]);
        $inv = $s->fetch();

        $s = $pdo->prepare('SELECT COUNT(*) total_opened, COALESCE(SUM(cost),0) total_spent, COALESCE(SUM(payout),0) total_won FROM open_history WHERE user_id = ?');
        $s->execute([$user['id']]);
        $opens = $s->fetch();

        return [
            'user' => $user,
            'stats' => [
                'inventory_count' => (int)$inv['count'],
                'inventory_value' => round((float)$inv['total_val'], 2),
                'total_cases_opened' => (int)$opens['total_opened'],
                'total_spent' => round((float)$opens['total_spent'], 2),
                'total_won' => round((float)$opens['total_won'], 2),
            ],
        ];
    }

    public static function forgotPassword(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $newPassword = (string)($input['new_password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($newPassword) < 6) {
            Response::error('Invalid password reset request.', 400);
        }

        $ip = Request::clientIp();
        if (!RateLimiter::allow('forgot:' . $ip, 8, 60)) {
            Response::error('Too many password reset attempts. Please try again later.', 429);
        }

        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = ? AND LOWER(email) = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        $user = $stmt->fetch();
        if (!$user) {
            Logger::security('PASSWORD_RESET_FAILED', ['username' => substr($username, 0, 80)]);
            Response::error('Không tìm thấy tài khoản với Tên đăng nhập và Email này', 404);
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        if (!$hash) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $pdo = Database::connection();
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$hash, $user['id']]);

        $audit = $pdo->prepare('
            INSERT INTO audit_logs (user_id, action, ip_address, user_agent, details)
            VALUES (?, "PASSWORD_RESET", ?, ?, ?)
        ');
        $audit->execute([$user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '', "Password reset by demo flow for {$username}"]);

        Logger::security('PASSWORD_RESET', ['user_id' => (int)$user['id']]);
        return ['success' => true, 'message' => 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập ngay.'];
    }

    private static function userResponseById(int $userId, bool $includeCompatToken = false): array
    {
        $stmt = Database::connection()->prepare('SELECT id, username, email, role, balance, daily_streak, last_daily_claim, avatar_url, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $response = [
            'access_token' => null,
            'token_type' => 'Session',
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'balance' => (float)$user['balance'],
                'daily_streak' => (int)$user['daily_streak'],
                'last_daily_claim' => $user['last_daily_claim'],
                'avatar_url' => $user['avatar_url'] ?? '',
            ],
        ];

        return $response;
    }
}
