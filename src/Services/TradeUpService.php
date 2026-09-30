<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;
use CS2\Security\ProvablyFairService;
use CS2\Utils\Logger;
use CS2\Utils\Response;
use PDO;

final class TradeUpService
{
    public static function execute(array $input, array $user): array
    {
        $ids = array_values(array_unique(array_map('intval', (array)($input['inventory_ids'] ?? []))));
        if (count($ids) !== 10) Response::error('Exactly 10 distinct inventory items are required', 400);

        return Database::transaction(function(PDO $pdo) use ($ids, $user): array {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge($ids, [$user['id']]);

            $stmt = $pdo->prepare("
                SELECT i.id, i.float_value, i.is_stattrak, i.value,
                       s.id skin_id, s.case_id, s.rarity_tier, s.rarity, s.name, s.weapon
                FROM inventory i JOIN skins s ON i.skin_id = s.id
                WHERE i.id IN ({$placeholders}) AND i.user_id = ? AND i.is_sold = 0
                FOR UPDATE
            ");
            $stmt->execute($params);
            $items = $stmt->fetchAll();
            if (count($items) !== 10) Response::error('One or more items are not found or already sold', 400);

            $tiers = array_unique(array_map(fn($x)=>(int)$x['rarity_tier'], $items));
            if (count($tiers) !== 1) Response::error('All 10 trade-up items must be of the exact same rarity tier', 400);
            $inputTier = (int)array_values($tiers)[0];
            if ($inputTier >= 5) Response::error('Special Rare items (Knives/Gloves) cannot be traded up', 400);
            $outputTier = $inputTier + 1;

            $avgFloat = array_sum(array_map(fn($x)=>(float)$x['float_value'], $items)) / 10.0;
            $stCount = count(array_filter($items, fn($x)=>(int)$x['is_stattrak'] === 1));
            $isOutputSt = random_int(0, 999999) < (int)round(($stCount / 10.0) * 1000000);

            $inputCases = array_values(array_unique(array_map(fn($x)=>$x['case_id'], $items)));
            $chosenCase = $inputCases[random_int(0, count($inputCases)-1)];

            $stmt = $pdo->prepare('SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float FROM skins WHERE case_id = ? AND rarity_tier = ?');
            $stmt->execute([$chosenCase, $outputTier]);
            $outputs = $stmt->fetchAll();

            if (!$outputs) {
                $stmt = $pdo->prepare('SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float FROM skins WHERE rarity_tier = ?');
                $stmt->execute([$outputTier]);
                $outputs = $stmt->fetchAll();
            }

            if (!$outputs) Response::error('No valid target tier skins available for trade-up', 500);
            $target = $outputs[random_int(0, count($outputs)-1)];

            $outFloat = round((float)$target['min_float'] + $avgFloat * ((float)$target['max_float'] - (float)$target['min_float']), 6);
            [$wearName, $wearMultiplier] = ProvablyFairService::wear($outFloat);
            $value = round((float)$target['base_price'] * $wearMultiplier * ($isOutputSt ? 2.2 : 1.0), 2);

            $pdo->prepare("UPDATE inventory SET is_sold = 1 WHERE id IN ({$placeholders})")->execute($ids);
            $ins = $pdo->prepare('INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold) VALUES (?, ?, ?, ?, ?, ?, 0)');
            $ins->execute([$user['id'], $target['id'], $outFloat, $wearName, $isOutputSt ? 1 : 0, $value]);
            $newId = (int)$pdo->lastInsertId();

            $hist = $pdo->prepare('INSERT INTO tradeup_history (user_id, input_inventory_ids, output_inventory_id, input_tier, output_tier, output_float) VALUES (?, ?, ?, ?, ?, ?)');
            $hist->execute([$user['id'], implode(',', $ids), $newId, $inputTier, $outputTier, $outFloat]);

            return [
                'success' => true,
                'reward' => [
                    'inventory_id' => $newId,
                    'skin_id' => $target['id'],
                    'name' => $target['name'],
                    'weapon' => $target['weapon'],
                    'skin_name' => $target['skin_name'],
                    'rarity' => $target['rarity'],
                    'rarity_name' => $target['rarity_name'],
                    'rarity_color' => $target['rarity_color'],
                    'rarity_tier' => $outputTier,
                    'image' => $target['image'],
                    'float_value' => $outFloat,
                    'wear_name' => $wearName,
                    'is_stattrak' => $isOutputSt,
                    'value' => $value,
                ],
            ];
        });
    }
}
