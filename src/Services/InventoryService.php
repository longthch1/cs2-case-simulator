<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;
use CS2\Utils\Logger;
use CS2\Utils\Request;
use CS2\Utils\Response;
use PDO;

final class InventoryService
{
    public static function list(array $user): array
    {
        $pdo = Database::connection();
        $sql = '
            SELECT i.id, i.float_value, i.wear_name, i.is_stattrak, i.value, i.acquired_at,
                   s.id skin_id, s.name, s.weapon, s.skin_name, s.rarity, s.rarity_name,
                   s.rarity_color, s.rarity_tier, s.image
            FROM inventory i
            JOIN skins s ON i.skin_id = s.id
            WHERE i.user_id = ? AND i.is_sold = 0
        ';
        $params = [$user['id']];

        $rarity = $_GET['rarity_tier'] ?? null;
        if ($rarity !== null && $rarity !== '') {
            $sql .= ' AND s.rarity_tier = ?';
            $params[] = (int)$rarity;
        }

        $st = $_GET['is_stattrak'] ?? null;
        if ($st !== null && $st !== '') {
            $sql .= ' AND i.is_stattrak = ?';
            $params[] = (int)$st;
        }

        $sortMap = [
            'date_desc' => ' ORDER BY i.acquired_at DESC',
            'date_asc' => ' ORDER BY i.acquired_at ASC',
            'value_desc' => ' ORDER BY i.value DESC',
            'value_asc' => ' ORDER BY i.value ASC',
            'float_asc' => ' ORDER BY i.float_value ASC',
            'rarity_desc' => ' ORDER BY s.rarity_tier DESC, i.value DESC',
        ];
        $sql .= $sortMap[(string)($_GET['sort_by'] ?? 'date_desc')] ?? $sortMap['date_desc'];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['float_value'] = (float)$item['float_value'];
            $item['is_stattrak'] = (bool)$item['is_stattrak'];
            $item['value'] = (float)$item['value'];
            $item['rarity_tier'] = (int)$item['rarity_tier'];
        }

        $s = $pdo->prepare('SELECT COUNT(*) count, COALESCE(SUM(value),0) total_val FROM inventory WHERE user_id = ? AND is_sold = 0');
        $s->execute([$user['id']]);
        $summary = $s->fetch();

        return [
            'items' => $items,
            'total_count' => (int)$summary['count'],
            'total_value' => round((float)$summary['total_val'], 2),
        ];
    }

    public static function sell(int $inventoryId, array $user): array
    {
        return Database::transaction(function (PDO $pdo) use ($inventoryId, $user): array {
            $stmt = $pdo->prepare('
                SELECT i.id, i.value, s.name
                FROM inventory i
                JOIN skins s ON i.skin_id = s.id
                WHERE i.id = ? AND i.user_id = ? AND i.is_sold = 0
                FOR UPDATE
            ');
            $stmt->execute([$inventoryId, $user['id']]);
            $item = $stmt->fetch();
            if (!$item) Response::error('Item not found or already sold', 404);

            $amount = (float)$item['value'];
            $pdo->prepare('UPDATE inventory SET is_sold = 1 WHERE id = ?')->execute([$inventoryId]);
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amount, $user['id']]);

            $b = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $b->execute([$user['id']]);
            $newBalance = round((float)$b->fetch()['balance'], 2);

            $tx = $pdo->prepare('
                INSERT INTO transactions (user_id, type, amount, balance_after, description)
                VALUES (?, "skin_sell", ?, ?, ?)
            ');
            $tx->execute([$user['id'], $amount, $newBalance, sprintf('Sold %s for $%.2f', $item['name'], $amount)]);

            Logger::audit(sprintf(
                'USER_SELL user=%s id=%d inventory=%d item=%s amount=%.2f ip=%s',
                $user['username'], $user['id'], $inventoryId, $item['name'], $amount, Request::clientIp()
            ));

            return ['success' => true, 'sold_item_id' => $inventoryId, 'amount' => $amount, 'new_balance' => $newBalance];
        });
    }

    public static function sellAll(array $user): array
    {
        return Database::transaction(function(PDO $pdo) use ($user): array {
            $stmt = $pdo->prepare('SELECT id, value FROM inventory WHERE user_id = ? AND is_sold = 0 FOR UPDATE');
            $stmt->execute([$user['id']]);
            $items = $stmt->fetchAll();

            if (!$items) {
                return ['success' => true, 'sold_count' => 0, 'amount' => 0.0, 'new_balance' => (float)$user['balance']];
            }

            $total = 0.0;
            foreach ($items as $it) $total += (float)$it['value'];
            $total = round($total, 2);

            $pdo->prepare('UPDATE inventory SET is_sold = 1 WHERE user_id = ? AND is_sold = 0')->execute([$user['id']]);
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$total, $user['id']]);

            $b = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $b->execute([$user['id']]);
            $newBalance = round((float)$b->fetch()['balance'], 2);

            $tx = $pdo->prepare('
                INSERT INTO transactions (user_id, type, amount, balance_after, description)
                VALUES (?, "skin_sell", ?, ?, ?)
            ');
            $tx->execute([$user['id'], $total, $newBalance, sprintf('Sold all %d inventory items', count($items))]);

            Logger::audit(sprintf(
                'USER_SELL_ALL user=%s id=%d count=%d amount=%.2f ip=%s',
                $user['username'], $user['id'], count($items), $total, Request::clientIp()
            ));

            return ['success' => true, 'sold_count' => count($items), 'amount' => $total, 'new_balance' => $newBalance];
        });
    }
}
