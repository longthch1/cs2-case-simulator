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

final class DailyService
{
    private const REWARDS = [
        1 => ['day'=>1,'reward'=>10.00,'title'=>'Khởi Đầu','icon'=>'fa-gift'],
        2 => ['day'=>2,'reward'=>15.00,'title'=>'Kiên Trì','icon'=>'fa-cube'],
        3 => ['day'=>3,'reward'=>25.00,'title'=>'Bền Bỉ','icon'=>'fa-shield-halved'],
        4 => ['day'=>4,'reward'=>40.00,'title'=>'Cao Thủ','icon'=>'fa-medal'],
        5 => ['day'=>5,'reward'=>60.00,'title'=>'Vinh Quang','icon'=>'fa-gem'],
        6 => ['day'=>6,'reward'=>90.00,'title'=>'Bậc Thầy','icon'=>'fa-fire'],
        7 => ['day'=>7,'reward'=>150.00,'title'=>'★ ĐẠI THƯỞNG DAO VÀNG','icon'=>'fa-crown'],
    ];

    private static function parse(?string $value): ?DateTimeImmutable
    {
        if (!$value) return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function calculateStatus(array $user): array
    {
        $streak = (int)($user['daily_streak'] ?? 0);
        $last = self::parse((string)($user['last_daily_claim'] ?? ''));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $today = $now->setTime(0,0,0);

        if (!$last) {
            return ['can_claim'=>true,'current_streak'=>0,'next_day'=>1,'hours_left'=>0,'last_claim'=>null];
        }

        $lastDate = $last->setTime(0,0,0);
        $days = (int)$lastDate->diff($today)->format('%r%a');

        if ($days === 0) {
            $midnight = $today->modify('+1 day');
            $hoursLeft = max(1, (int)floor(($midnight->getTimestamp() - $now->getTimestamp()) / 3600));
            return ['can_claim'=>false,'current_streak'=>$streak,'next_day'=>min(7, ($streak % 7)+1),'hours_left'=>$hoursLeft,'last_claim'=>$user['last_daily_claim']];
        }

        if ($days === 1) {
            return ['can_claim'=>true,'current_streak'=>$streak,'next_day'=>min(7, ($streak % 7)+1),'hours_left'=>0,'last_claim'=>$user['last_daily_claim']];
        }

        return ['can_claim'=>true,'current_streak'=>0,'next_day'=>1,'hours_left'=>0,'last_claim'=>$user['last_daily_claim'],'streak_reset'=>true];
    }

    public static function status(array $user): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, daily_streak, last_daily_claim, balance FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $dbUser = $stmt->fetch();

        $info = self::calculateStatus($dbUser ?: $user);
        $schedule = [];
        for ($d=1; $d<=7; $d++) {
            $claimed = !$info['can_claim'] ? $d <= $info['current_streak'] : $d < $info['next_day'];
            $schedule[] = [
                'day'=>$d,
                'reward'=>self::REWARDS[$d]['reward'],
                'title'=>self::REWARDS[$d]['title'],
                'icon'=>self::REWARDS[$d]['icon'],
                'claimed'=>$claimed,
                'is_today'=>($d === $info['next_day'] && $info['can_claim']),
            ];
        }

        return [
            'can_claim'=>$info['can_claim'],
            'current_streak'=>$info['current_streak'],
            'next_day'=>$info['next_day'],
            'next_reward'=>self::REWARDS[$info['next_day']]['reward'],
            'hours_left'=>$info['hours_left'],
            'schedule'=>$schedule,
        ];
    }

    public static function claim(array $user): array
    {
        return Database::transaction(function(PDO $pdo) use ($user): array {
            $stmt = $pdo->prepare('SELECT id, username, balance, daily_streak, last_daily_claim FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$user['id']]);
            $dbUser = $stmt->fetch();
            if (!$dbUser) Response::error('Không tìm thấy người dùng', 404);

            $info = self::calculateStatus($dbUser);
            if (!$info['can_claim']) {
                Response::error("Bạn đã điểm danh hôm nay rồi! Vui lòng quay lại sau {$info['hours_left']} giờ.", 400);
            }

            $day = $info['next_day'];
            $reward = self::REWARDS[$day]['reward'];
            $newBalance = round((float)$dbUser['balance'] + $reward, 2);
            $now = gmdate('Y-m-d H:i:s');

            $upd = $pdo->prepare('UPDATE users SET balance = ?, daily_streak = ?, last_daily_claim = ? WHERE id = ?');
            $upd->execute([$newBalance, $day, $now, $dbUser['id']]);

            $tx = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, "daily_reward", ?, ?, ?)');
            $tx->execute([$dbUser['id'], $reward, $newBalance, "Điểm danh nhận quà ngày {$day}/7"]);

            $audit = $pdo->prepare('INSERT INTO audit_logs (user_id, action, ip_address, user_agent, details) VALUES (?, "DAILY_CLAIM", ?, ?, ?)');
            $audit->execute([$dbUser['id'], Request::clientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '', "Claimed Day {$day} (+{$reward})"]);

            return [
                'success'=>true,
                'day'=>$day,
                'amount'=>$reward,
                'new_balance'=>$newBalance,
                'streak'=>$day,
                'message'=>sprintf('Chúc mừng! Bạn đã nhận thành công +$%.2f quà đăng nhập Ngày %d!', $reward, $day),
            ];
        });
    }
}
