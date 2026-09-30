<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Auth\AuthService;
use CS2\Config\Config;
use CS2\Database\Database;
use CS2\Utils\Logger;
use CS2\Utils\Response;
use PDO;

final class AdminService
{
    public static function overview(): array
    {
        $pdo = Database::connection();

        $u = $pdo->query('SELECT COUNT(*) user_count, COALESCE(SUM(balance),0) total_balances FROM users')->fetch();
        $o = $pdo->query('SELECT COUNT(*) total_cases, COALESCE(SUM(cost),0) total_spent, COALESCE(SUM(payout),0) total_payout FROM open_history')->fetch();
        $inv = $pdo->query('SELECT COUNT(*) total_skins FROM inventory WHERE is_sold = 0')->fetch();
        $sold = $pdo->query('SELECT COUNT(*) total_sold FROM inventory WHERE is_sold = 1')->fetch();
        $tu = $pdo->query('SELECT COUNT(*) count FROM tradeup_history')->fetch();
        $cr = $pdo->query('SELECT COUNT(*) total_redeems, COALESCE(SUM(amount),0) total_gift_amount FROM code_redemptions')->fetch();
        $code = WalletService::activeCode(null);

        $spent = (float)$o['total_spent'];
        $payout = (float)$o['total_payout'];
        $metrics = MetricsService::systemStats();

        return [
            'platform'=>[
                'total_registered_users'=>(int)$u['user_count'],
                'total_circulating_balance'=>round((float)$u['total_balances'],2),
                'total_cases_opened'=>(int)$o['total_cases'],
                'total_revenue_usd'=>round($spent,2),
                'total_payout_usd'=>round($payout,2),
                'house_profit_usd'=>round($spent-$payout,2),
                'rtp_percentage'=>$spent > 0 ? round($payout/$spent*100,2) : 0.0,
                'active_items_in_inventories'=>(int)$inv['total_skins'],
                'total_skins_sold'=>(int)$sold['total_sold'],
                'tradeups_completed'=>(int)$tu['count'],
                'total_code_redemptions'=>(int)$cr['total_redeems'],
                'total_gift_distributed'=>round((float)$cr['total_gift_amount'],2),
                'active_hourly_code'=>$code['code'],
                'code_expires_in_secs'=>$code['seconds_left'],
            ],
            'system_health'=>$metrics,
        ];
    }

    public static function users(): array
    {
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $q = trim((string)($_GET['q'] ?? ''));
        $role = trim((string)($_GET['role'] ?? ''));

        $sql = '
            SELECT u.id, u.username, u.email, u.role, u.balance, u.daily_streak, u.created_at, u.last_login,
                   COUNT(DISTINCT o.id) cases_opened,
                   COUNT(DISTINCT CASE WHEN i.is_sold = 0 THEN i.id END) inventory_count,
                   COALESCE(SUM(o.cost),0) total_spent
            FROM users u
            LEFT JOIN open_history o ON u.id = o.user_id
            LEFT JOIN inventory i ON u.id = i.user_id
            WHERE 1=1
        ';
        $params=[];
        if ($q !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.email LIKE ?)';
            $params[]="%{$q}%"; $params[]="%{$q}%";
        }
        if ($role !== '') {
            $sql .= ' AND u.role = ?'; $params[]=$role;
        }
        $sql .= " GROUP BY u.id ORDER BY u.created_at DESC, u.id DESC LIMIT {$limit}";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return ['users'=>$stmt->fetchAll(),'total'=>count($stmt->fetchAll())];
    }

    public static function adjustBalance(int $targetId, array $input, array $admin): array
    {
        $amount = (float)($input['amount'] ?? 0);
        $mode = ($input['mode'] ?? 'set') === 'add' ? 'add' : 'set';

        $result = Database::transaction(function(PDO $pdo) use ($targetId,$amount,$mode,$admin): array {
            $s=$pdo->prepare('SELECT id, username, balance FROM users WHERE id=? FOR UPDATE');
            $s->execute([$targetId]);
            $target=$s->fetch();
            if(!$target) Response::error('Không tìm thấy người dùng',404);

            $old=(float)$target['balance'];
            $new=$mode==='add' ? round($old+$amount,2) : round($amount,2);
            if($new<0) Response::error('Số dư không thể âm!',400);

            $pdo->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$new,$targetId]);
            $delta=round($new-$old,2);
            $pdo->prepare('INSERT INTO transactions (user_id,type,amount,balance_after,description) VALUES (?,"admin_adjustment",?,?,?)')
                ->execute([$targetId,$delta,$new,"Admin {$admin['username']} adjustment ({$mode}: {$amount})"]);

            $pdo->prepare('INSERT INTO audit_logs (user_id,action,ip_address,user_agent,details) VALUES (?, "ADMIN_BALANCE_ADJUST", ?, ?, ?)')
                ->execute([$admin['id'], $_SERVER['REMOTE_ADDR']??'unknown', $_SERVER['HTTP_USER_AGENT']??'', "target={$targetId} old={$old} new={$new}"]);

            return ['success'=>true,'user_id'=>$targetId,'username'=>$target['username'],'old_balance'=>$old,'new_balance'=>$new,'message'=>"Đã cập nhật số dư cho {$target['username']} thành $" . number_format($new,2)];
        });

        Logger::audit("ADMIN_BALANCE admin={$admin['username']} target={$targetId}");
        return $result;
    }

    public static function updateRole(int $targetId, array $input, array $admin): array
    {
        $role = ($input['role'] ?? '') === 'admin' ? 'admin' : (($input['role'] ?? '') === 'user' ? 'user' : '');
        if ($role === '') Response::error('Invalid role',400);
        if ($targetId === (int)$admin['id'] && $role !== 'admin') Response::error('Không thể tự hạ quyền của chính bạn!',400);

        $s=Database::connection()->prepare('SELECT username FROM users WHERE id=?');
        $s->execute([$targetId]); $target=$s->fetch();
        if(!$target) Response::error('Không tìm thấy người dùng',404);

        Database::connection()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$targetId]);
        Logger::audit("ADMIN_ROLE admin={$admin['username']} target={$targetId} role={$role}");
        return ['success'=>true,'user_id'=>$targetId,'username'=>$target['username'],'new_role'=>$role,'message'=>"Đã cập nhật quyền của {$target['username']} thành " . strtoupper($role)];
    }

    public static function userInventory(int $targetId): array
    {
        $s=Database::connection()->prepare('SELECT username FROM users WHERE id=?');
        $s->execute([$targetId]); $target=$s->fetch();
        if(!$target) Response::error('Không tìm thấy người dùng',404);

        $stmt=Database::connection()->prepare('
            SELECT i.id,i.float_value,i.wear_name,i.is_stattrak,i.value,i.is_sold,i.acquired_at,
                   s.name,s.weapon,s.skin_name,s.rarity_tier,s.rarity_name,s.rarity_color,s.image
            FROM inventory i JOIN skins s ON i.skin_id=s.id
            WHERE i.user_id=? ORDER BY i.acquired_at DESC, i.id DESC
        ');
        $stmt->execute([$targetId]);
        return ['username'=>$target['username'],'items'=>$stmt->fetchAll(),'count'=>$stmt->rowCount()];
    }

    public static function cases(): array
    {
        $stmt=Database::connection()->query('
            SELECT c.id,c.name,c.description,c.price,c.key_price,c.image,c.is_active,
                   COUNT(DISTINCT s.id) total_items, COUNT(DISTINCT o.id) total_opened
            FROM cases c
            LEFT JOIN skins s ON c.id=s.case_id
            LEFT JOIN open_history o ON c.id=o.case_id
            GROUP BY c.id ORDER BY c.price DESC
        ');
        return ['cases'=>$stmt->fetchAll()];
    }

    public static function updateCase(string $caseId, array $input): array
    {
        $price=(float)($input['price']??0);
        $active=(int)($input['is_active']??1);
        if($price<1 || $price>100) Response::error('Case price must be between 1 and 100.',400);

        $s=Database::connection()->prepare('SELECT name FROM cases WHERE id=?');
        $s->execute([$caseId]); $case=$s->fetch();
        if(!$case) Response::error('Không tìm thấy rương',404);

        Database::connection()->prepare('UPDATE cases SET price=?,is_active=? WHERE id=?')->execute([round($price,2),$active?1:0,$caseId]);
        Logger::audit("ADMIN_CASE_UPDATE case={$caseId} price={$price} active={$active}");
        return ['success'=>true,'case_id'=>$caseId,'price'=>$price,'is_active'=>$active?1:0,'message'=>"Cập nhật rương {$case['name']} thành công!"];
    }

    public static function giftcode(): array
    {
        $code=WalletService::activeCode(null);
        $stmt=Database::connection()->query('
            SELECT r.id,r.code,r.amount,r.redeemed_at,u.username,u.email
            FROM code_redemptions r JOIN users u ON r.user_id=u.id
            ORDER BY r.redeemed_at DESC LIMIT 50
        ');
        $rows=$stmt->fetchAll();
        $s=Database::connection()->prepare('SELECT COUNT(*) count, COALESCE(SUM(amount),0) total FROM code_redemptions WHERE code=?');
        $s->execute([$code['code']]); $stats=$s->fetch();

        return ['active_code'=>$code,'current_code_redemptions'=>(int)$stats['count'],'current_code_total_paid'=>(float)$stats['total'],'recent_redemptions'=>$rows];
    }

    public static function generateGiftcode(array $admin): array
    {
        $pdo=Database::connection();
        $now=gmdate('Y-m-d H:i:s');
        $pdo->prepare('UPDATE hourly_codes SET is_active=0, expires_at=? WHERE expires_at>UTC_TIMESTAMP() AND is_active=1')->execute([$now]);
        $code=(string)random_int(100000,999999);
        $exp=gmdate('Y-m-d H:i:s',time()+3600);
        $pdo->prepare('INSERT INTO hourly_codes (code,reward_amount,created_at,expires_at,is_active) VALUES (?,100.0,?,?,1)')->execute([$code,$now,$exp]);
        Logger::audit("ADMIN_GIFTCODE_GENERATE admin={$admin['username']} code={$code}");
        return ['success'=>true,'code'=>$code,'reward_amount'=>100.0,'expires_at'=>$exp,'message'=>"Đã phát sinh mã Giftcode mới: {$code} (Hiệu lực 1 tiếng)"];
    }

    public static function setReward(array $input, array $admin): array
    {
        $reward=(float)($input['reward_amount']??0);
        if($reward<1 || $reward>1000) Response::error('Reward must be between 1 and 1000.',400);
        $code=WalletService::activeCode(null);
        Database::connection()->prepare('UPDATE hourly_codes SET reward_amount=? WHERE code=?')->execute([round($reward,2),$code['code']]);
        Logger::audit("ADMIN_GIFT_REWARD admin={$admin['username']} code={$code['code']} reward={$reward}");
        return ['success'=>true,'reward_amount'=>$reward,'message'=>"Đã đổi phần thưởng giftcode thành $" . number_format($reward,2)];
    }

    public static function audit(int $limit): array
    {
        $limit=max(1,min(500,$limit));
        $stmt=Database::connection()->query('
            SELECT a.id,a.user_id,u.username,a.action,a.ip_address,a.details,a.created_at
            FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id
            ORDER BY a.created_at DESC,a.id DESC
            LIMIT ' . $limit
        );
        return ['audit_trail'=>$stmt->fetchAll()];
    }

    public static function logs(string $type,int $lines): array
    {
        $lines=max(1,min(500,$lines));
        $file=Config::logDir() . '/' . ($type==='audit' ? 'audit.log' : 'app.log');
        if(!is_file($file)) return ['logs'=>['Log file does not exist yet.']];
        $data=@file($file,FILE_IGNORE_NEW_LINES);
        if($data===false) return ['logs'=>['Unable to read log file.']];
        return ['logs'=>array_slice($data,-$lines)];
    }
}
