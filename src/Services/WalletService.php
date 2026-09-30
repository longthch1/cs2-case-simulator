<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;
use CS2\Utils\Logger;
use CS2\Utils\Request;
use CS2\Utils\Response;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class WalletService
{
    private static function loadActiveCode(PDO $pdo): array
    {
        $stmt = $pdo->query('
            SELECT id, code, reward_amount, created_at, expires_at, is_active
            FROM hourly_codes
            WHERE expires_at > UTC_TIMESTAMP() AND is_active = 1
            ORDER BY id DESC
            LIMIT 1
        ');
        $row = $stmt->fetch();

        if ($row) {
            $exp = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
            $seconds = max(0, $exp->getTimestamp() - time());
            return [
                'id'=>(int)$row['id'],
                'code'=>$row['code'],
                'reward_amount'=>(float)$row['reward_amount'],
                'expires_at'=>$row['expires_at'],
                'seconds_left'=>$seconds,
            ];
        }

        $code = (string)random_int(100000, 999999);
        $created = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $ins = $pdo->prepare('INSERT INTO hourly_codes (code, reward_amount, created_at, expires_at, is_active) VALUES (?, 100.0, ?, ?, 1)');
        $ins->execute([$code, $created, $expires]);
        return [
            'id'=>(int)$pdo->lastInsertId(),
            'code'=>$code,
            'reward_amount'=>100.0,
            'expires_at'=>$expires,
            'seconds_left'=>3600,
        ];
    }

    public static function activeCode(?array $user): array
    {
        $pdo = Database::connection();
        $code = self::loadActiveCode($pdo);
        $hasRedeemed = false;

        if ($user) {
            $stmt = $pdo->prepare('SELECT id FROM code_redemptions WHERE user_id = ? AND code = ? LIMIT 1');
            $stmt->execute([$user['id'], $code['code']]);
            $hasRedeemed = (bool)$stmt->fetch();
        }

        return [
            'code'=>$code['code'],
            'reward_amount'=>$code['reward_amount'],
            'seconds_left'=>$code['seconds_left'],
            'expires_at'=>$code['expires_at'],
            'has_redeemed'=>$hasRedeemed,
        ];
    }

    public static function redeem(array $input, array $user): array
    {
        $code = trim((string)($input['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) Response::error('Mã code phải gồm đúng 6 chữ số (ví dụ: 123456)!', 400);

        return Database::transaction(function(PDO $pdo) use ($code, $user): array {
            $active = self::loadActiveCode($pdo);
            if ($active['code'] !== $code) {
                Response::error('Mã code không chính xác hoặc đã hết hiệu lực.', 400);
            }

            $check = $pdo->prepare('SELECT id FROM code_redemptions WHERE user_id = ? AND code = ? LIMIT 1');
            $check->execute([$user['id'], $code]);
            if ($check->fetch()) Response::error('Bạn đã sử dụng mã code này rồi!', 400);

            $reward = (float)$active['reward_amount'];
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$reward, $user['id']]);
            $b = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $b->execute([$user['id']]);
            $balance = round((float)$b->fetch()['balance'], 2);

            $pdo->prepare('INSERT INTO code_redemptions (user_id, code, amount) VALUES (?, ?, ?)')->execute([$user['id'], $code, $reward]);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, "deposit", ?, ?, ?)')->execute([$user['id'], $reward, $balance, "Nạp tiền qua Giftcode 1 tiếng: {$code}"]);
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, ip_address, user_agent, details) VALUES (?, "CODE_REDEEM", ?, ?, ?)')->execute([$user['id'], Request::clientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '', "Redeemed code {$code} for +{$reward}"]);

            Logger::audit("CODE_REDEEM user={$user['username']} code={$code} reward={$reward}");
            return ['success'=>true,'amount'=>$reward,'new_balance'=>$balance,'message'=>sprintf('Chúc mừng! Bạn đã nạp thành công +$%.2f vào tài khoản từ mã code %s!', $reward, $code)];
        });
    }

    public static function deposit(array $input, array $user): array
    {
        $amount = round((float)($input['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > 10000) Response::error('Số tiền nạp phải lớn hơn 0 và không quá $10,000.', 400);

        return Database::transaction(function(PDO $pdo) use ($amount, $user): array {
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amount, $user['id']]);
            $b = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $b->execute([$user['id']]);
            $balance = round((float)$b->fetch()['balance'], 2);
            $pdo->prepare('INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, "deposit", ?, ?, ?)')->execute([$user['id'], $amount, $balance, sprintf('Nạp +$%.2f', $amount)]);
            return ['success'=>true,'deposited'=>$amount,'new_balance'=>$balance];
        });
    }

    public static function transactions(array $user): array
    {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $stmt = Database::connection()->prepare('SELECT id, type, amount, balance_after, description, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $stmt->execute([$user['id']]);
        return ['transactions'=>$stmt->fetchAll()];
    }
}
