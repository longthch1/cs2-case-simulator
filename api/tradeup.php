<?php
/**
 * CS2 Case Opening Simulator — Trade-Up Contract API
 * Route: POST /api/tradeup
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';

setCorsHeaders();

$method = getMethod();

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

$user   = requireAuth();
$userId = (int)$user['id'];
$body   = getRequestBody();
$rawIds = $body['inventory_ids'] ?? [];

if (!is_array($rawIds)) {
    jsonError('Dữ liệu không hợp lệ.', 400);
}

$uniqueIds = array_values(array_unique(array_map('intval', $rawIds)));

if (count($uniqueIds) !== 10) {
    jsonError('Hợp đồng Trade-Up yêu cầu đúng chính xác 10 vật phẩm khác nhau.', 400);
}

$db = getDB();
$db->beginTransaction();

try {
    $placeholders = implode(',', array_fill(0, 10, '?'));
    $stmt = $db->prepare(
        "SELECT i.id, i.float_value, i.is_stattrak, i.value,
                s.id AS skin_id, s.case_id, s.rarity_tier, s.rarity, s.name, s.weapon
         FROM inventory i
         JOIN skins s ON i.skin_id = s.id
         WHERE i.id IN ($placeholders) AND i.user_id = ? AND i.is_sold = 0
         FOR UPDATE"
    );
    $params = array_merge($uniqueIds, [$userId]);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    if (count($items) !== 10) {
        $db->rollBack();
        jsonError('Một hoặc nhiều vật phẩm không tồn tại hoặc đã bị bán.', 400);
    }

    $tiers = array_unique(array_map(fn($it) => (int)$it['rarity_tier'], $items));
    if (count($tiers) !== 1) {
        $db->rollBack();
        jsonError('Tất cả 10 vật phẩm phải có cùng cấp độ hiếm (cùng Rarity Tier).', 400);
    }

    $inputTier = $tiers[0];
    if ($inputTier >= 5) {
        $db->rollBack();
        jsonError('Vũ khí Dao/Găng tay (Special Rare Tier 5) không thể dùng để Trade-Up.', 400);
    }

    $outputTier = $inputTier + 1;

    // Average float
    $avgFloat = array_sum(array_map(fn($it) => (float)$it['float_value'], $items)) / 10.0;

    // StatTrak chance
    $stCount = count(array_filter($items, fn($it) => !empty($it['is_stattrak'])));
    $isOutputSt = (mt_rand(1, 100) / 100.0) <= ($stCount / 10.0);

    // Pick case among inputs
    $inputCases = array_map(fn($it) => $it['case_id'], $items);
    $chosenCase = $inputCases[array_rand($inputCases)];

    // Target skins in chosen case with outputTier
    $stmt = $db->prepare(
        'SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
         FROM skins
         WHERE case_id = ? AND rarity_tier = ?'
    );
    $stmt->execute([$chosenCase, $outputTier]);
    $possibleOutputs = $stmt->fetchAll();

    // Fallback: any skin of outputTier
    if (empty($possibleOutputs)) {
        $stmt = $db->prepare(
            'SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
             FROM skins
             WHERE rarity_tier = ?'
        );
        $stmt->execute([$outputTier]);
        $possibleOutputs = $stmt->fetchAll();
    }

    if (empty($possibleOutputs)) {
        $db->rollBack();
        jsonError('Không tìm thấy skin nào ở bậc cao hơn để hoàn thành hợp đồng.', 500);
    }

    $targetSkin = $possibleOutputs[array_rand($possibleOutputs)];

    // Float formula: Min + AvgFloat * (Max - Min)
    $minF = (float)$targetSkin['min_float'];
    $maxF = (float)$targetSkin['max_float'];
    $outputFloat = round($minF + $avgFloat * ($maxF - $minF), 6);

    [$wearName, $wearMult] = ProvablyFairRNG::getWearCondition($outputFloat);
    $stMult      = $isOutputSt ? 2.2 : 1.0;
    $outputValue = round((float)$targetSkin['base_price'] * $wearMult * $stMult, 2);

    // Consume input items
    $stmt = $db->prepare("UPDATE inventory SET is_sold = 1 WHERE id IN ($placeholders)");
    $stmt->execute($uniqueIds);

    // Insert crafted item
    $stmt = $db->prepare(
        'INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold)
         VALUES (?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([$userId, $targetSkin['id'], $outputFloat, $wearName, $isOutputSt ? 1 : 0, $outputValue]);
    $newInvId = (int)$db->lastInsertId();

    // Record tradeup history
    $stmt = $db->prepare(
        'INSERT INTO tradeup_history (user_id, input_inventory_ids, output_inventory_id, input_tier, output_tier, output_float)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, json_encode($uniqueIds), $newInvId, $inputTier, $outputTier, $outputFloat]);

    $db->commit();

    $ip = getClientIp();
    logAudit($userId, 'TRADEUP', $ip, "Tradeup: crafted {$targetSkin['name']} worth \${$outputValue}");

    jsonResponse([
        'success'      => true,
        'crafted_item' => [
            'inventory_id' => $newInvId,
            'skin_id'      => $targetSkin['id'],
            'name'         => $targetSkin['name'],
            'weapon'       => $targetSkin['weapon'],
            'skin_name'    => $targetSkin['skin_name'],
            'rarity'       => $targetSkin['rarity'],
            'rarity_name'  => $targetSkin['rarity_name'],
            'rarity_color' => $targetSkin['rarity_color'],
            'rarity_tier'  => (int)$targetSkin['rarity_tier'],
            'image'        => $targetSkin['image'],
            'float_value'  => $outputFloat,
            'wear_name'    => $wearName,
            'is_stattrak'  => $isOutputSt,
            'value'        => $outputValue
        ],
        'message' => sprintf('Chúc mừng! Hợp đồng Trade-Up thành công! Bạn nhận được: %s ($%.2f)', $targetSkin['name'], $outputValue)
    ]);

} catch (Throwable $e) {
    $db->rollBack();
    writeLog('ERROR', 'Trade-Up failed: ' . $e->getMessage());
    jsonError('Đã xảy ra lỗi trong quá trình Trade-Up: ' . $e->getMessage(), 500);
}
