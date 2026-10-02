<?php
/**
 * CS2 Case Opening Simulator — Inventory API
 * Routes:
 *   GET  /api/inventory
 *   POST /api/inventory/{id}/sell
 *   POST /api/inventory/sell-all
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

$method = getMethod();
$path   = getApiPath('inventory');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user   = requireAuth();
$userId = (int)$user['id'];
$db     = getDB();

// ── GET /api/inventory ────────────────────────────────────────────────────────
if ($method === 'GET' && ($path === '' || $path === 'inventory')) {
    $rarityTier = isset($_GET['rarity_tier']) && $_GET['rarity_tier'] !== '' ? (int)$_GET['rarity_tier'] : null;
    $isStattrak = isset($_GET['is_stattrak']) && $_GET['is_stattrak'] !== '' ? (int)$_GET['is_stattrak'] : null;
    $sortBy     = $_GET['sort_by'] ?? 'date_desc';

    $sql = 'SELECT i.id, i.float_value, i.wear_name, i.is_stattrak, i.value, i.acquired_at,
                   s.id AS skin_id, s.name, s.weapon, s.skin_name, s.rarity, s.rarity_name,
                   s.rarity_color, s.rarity_tier, s.image
            FROM inventory i
            JOIN skins s ON i.skin_id = s.id
            WHERE i.user_id = ? AND i.is_sold = 0';
    $params = [$userId];

    if ($rarityTier !== null) {
        $sql .= ' AND s.rarity_tier = ?';
        $params[] = $rarityTier;
    }
    if ($isStattrak !== null) {
        $sql .= ' AND i.is_stattrak = ?';
        $params[] = $isStattrak;
    }

    $sortOptions = [
        'date_desc'   => ' ORDER BY i.acquired_at DESC',
        'date_asc'    => ' ORDER BY i.acquired_at ASC',
        'value_desc'  => ' ORDER BY i.value DESC',
        'value_asc'   => ' ORDER BY i.value ASC',
        'float_asc'   => ' ORDER BY i.float_value ASC',
        'rarity_desc' => ' ORDER BY s.rarity_tier DESC, i.value DESC'
    ];
    $sql .= $sortOptions[$sortBy] ?? ' ORDER BY i.acquired_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    foreach ($items as &$it) {
        $it['id']          = (int)$it['id'];
        $it['float_value'] = (float)$it['float_value'];
        $it['is_stattrak'] = (bool)$it['is_stattrak'];
        $it['value']       = (float)$it['value'];
        $it['rarity_tier'] = (int)$it['rarity_tier'];
    }
    unset($it);

    $stmt = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total_val FROM inventory WHERE user_id = ? AND is_sold = 0');
    $stmt->execute([$userId]);
    $summary = $stmt->fetch();

    jsonResponse([
        'items'       => $items,
        'total_count' => (int)$summary['cnt'],
        'total_value' => round((float)$summary['total_val'], 2)
    ]);
}

// ── POST /api/inventory/{id}/sell ─────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^(\d+)/sell$#', $path, $m)) {
    $invId = (int)$m[1];

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT i.id, i.value, s.name
             FROM inventory i
             JOIN skins s ON i.skin_id = s.id
             WHERE i.id = ? AND i.user_id = ? AND i.is_sold = 0
             FOR UPDATE'
        );
        $stmt->execute([$invId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            $db->rollBack();
            jsonError('Vật phẩm không tồn tại hoặc đã được bán.', 404);
        }

        $saleVal = (float)$item['value'];
        $db->prepare('UPDATE inventory SET is_sold = 1 WHERE id = ?')->execute([$invId]);

        $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$saleVal, $userId]);

        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $newBal = round((float)$stmt->fetch()['balance'], 2);

        $db->prepare(
            "INSERT INTO transactions (user_id, type, amount, balance_after, description)
             VALUES (?, 'skin_sell', ?, ?, ?)"
        )->execute([$userId, $saleVal, $newBal, "Bán {$item['name']}"]);

        $db->commit();

        $ip = getClientIp();
        logAudit($userId, 'SKIN_SELL', $ip, "Sold item {$invId} ({$item['name']}) for \${$saleVal}");

        jsonResponse([
            'success'     => true,
            'sold_value'  => $saleVal,
            'new_balance' => $newBal,
            'message'     => sprintf('Đã bán %s thành công, nhận +$%.2f!', $item['name'], $saleVal)
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('Lỗi khi bán vật phẩm: ' . $e->getMessage(), 500);
    }
}

// ── POST /api/inventory/sell-all ──────────────────────────────────────────────
if ($method === 'POST' && $path === 'sell-all') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT i.id, i.value FROM inventory i
             WHERE i.user_id = ? AND i.is_sold = 0
             FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();

        if (empty($items)) {
            $db->rollBack();
            jsonError('Kho đồ của bạn đang trống hoặc tất cả đã được bán.', 400);
        }

        $totalVal = array_sum(array_map(fn($r) => (float)$r['value'], $items));
        $count    = count($items);

        $db->prepare('UPDATE inventory SET is_sold = 1 WHERE user_id = ? AND is_sold = 0')->execute([$userId]);
        $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$totalVal, $userId]);

        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $newBal = round((float)$stmt->fetch()['balance'], 2);

        $db->prepare(
            "INSERT INTO transactions (user_id, type, amount, balance_after, description)
             VALUES (?, 'skin_sell', ?, ?, ?)"
        )->execute([$userId, $totalVal, $newBal, "Bán toàn bộ {$count} vật phẩm"]);

        $db->commit();

        $ip = getClientIp();
        logAudit($userId, 'SKIN_SELL_ALL', $ip, "Sold all {$count} items for \${$totalVal}");

        jsonResponse([
            'success'     => true,
            'items_sold'  => $count,
            'total_value' => round($totalVal, 2),
            'new_balance' => $newBal,
            'message'     => sprintf('Đã bán toàn bộ %d vật phẩm, nhận +$%.2f!', $count, $totalVal)
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('Lỗi khi bán toàn bộ: ' . $e->getMessage(), 500);
    }
}

jsonError('Endpoint not found', 404);
