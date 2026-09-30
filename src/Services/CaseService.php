<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;
use CS2\Security\ProvablyFairService;
use CS2\Utils\Logger;
use CS2\Utils\Request;
use CS2\Utils\Response;
use PDO;

final class CaseService
{
    private static function getSkins(PDO $pdo, string $caseId): array
    {
        $stmt = $pdo->prepare('
            SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
            FROM skins WHERE case_id = ? ORDER BY rarity_tier ASC, base_price ASC
        ');
        $stmt->execute([$caseId]);
        return $stmt->fetchAll();
    }

    public static function listCases(): array
    {
        $stmt = Database::connection()->query('
            SELECT c.id, c.name, c.description, c.price, c.key_price, c.image,
                   COUNT(s.id) total_items
            FROM cases c
            LEFT JOIN skins s ON c.id = s.case_id
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.price ASC
        ');
        $cases = $stmt->fetchAll();
        foreach ($cases as &$case) {
            $case['price'] = (float)$case['price'];
            $case['key_price'] = (float)$case['key_price'];
            $case['total_items'] = (int)$case['total_items'];
        }
        return ['cases' => $cases];
    }

    public static function detail(string $caseId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, description, price, key_price, image FROM cases WHERE id = ? AND is_active = 1');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) Response::error('Case not found', 404);

        $skins = self::getSkins($pdo, $caseId);
        $case['price'] = (float)$case['price'];
        $case['key_price'] = (float)$case['key_price'];

        foreach ($skins as &$skin) {
            $skin['base_price'] = (float)$skin['base_price'];
            $skin['min_float'] = (float)$skin['min_float'];
            $skin['max_float'] = (float)$skin['max_float'];
            $skin['rarity_tier'] = (int)$skin['rarity_tier'];
        }

        return [
            'case' => $case,
            'total_price' => round($case['price'] + $case['key_price'], 2),
            'skins' => $skins,
        ];
    }

    public static function open(string $caseId, array $input, array $user): array
    {
        $count = (int)($input['count'] ?? 1);
        if ($count < 1 || $count > 10) {
            Response::error('Opening count must be between 1 and 10.', 400);
        }

        $clientSeed = trim((string)($input['client_seed'] ?? ''));
        if ($clientSeed === '') $clientSeed = bin2hex(random_bytes(16));
        if (strlen($clientSeed) > 64) Response::error('client_seed is too long.', 400);

        if (!RateLimiter::allow('open:' . $user['id'], 30, 60)) {
            Logger::security('RATE_LIMIT', ['endpoint' => '/api/cases/open', 'user_id' => $user['id']]);
            Response::error('Opening too fast. Please slow down.', 429);
        }

        $result = Database::transaction(function (PDO $pdo) use ($caseId, $count, $clientSeed, $user): array {
            $caseStmt = $pdo->prepare('SELECT id, name, price, key_price FROM cases WHERE id = ? AND is_active = 1 FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch();
            if (!$case) Response::error('Case not found', 404);

            $balanceStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id = ? FOR UPDATE');
            $balanceStmt->execute([$user['id']]);
            $u = $balanceStmt->fetch();
            $unitCost = (float)$case['price'] + (float)$case['key_price'];
            $totalCost = round($unitCost * $count, 2);

            if (!$u || (float)$u['balance'] < $totalCost) {
                Response::error(sprintf(
                    'Insufficient balance. Opening %d case(s) costs $%.2f, but your balance is $%.2f.',
                    $count, $totalCost, (float)($u['balance'] ?? 0)
                ), 400);
            }

            $allSkins = self::getSkins($pdo, $caseId);
            if (!$allSkins) Response::error('Case has no configured skins', 500);

            $tierMap = [0=>[],1=>[],2=>[],3=>[],4=>[],5=>[]];
            foreach ($allSkins as $skin) {
                $tier = (int)$skin['rarity_tier'];
                $tierMap[$tier] ??= [];
                $tierMap[$tier][] = $skin;
            }

            $nonceStmt = $pdo->prepare('SELECT COUNT(*) cnt FROM open_history WHERE user_id = ?');
            $nonceStmt->execute([$user['id']]);
            $baseNonce = (int)$nonceStmt->fetch()['cnt'];

            $serverSeed = ProvablyFairService::generateServerSeed();
            $serverHash = ProvablyFairService::hashServerSeed($serverSeed);
            $totalPayout = 0.0;
            $drops = [];
            $hasTier0 = count($tierMap[0]) > 0;

            for ($i = 0; $i < $count; $i++) {
                $nonce = $baseNonce + $i + 1;
                $roll = ProvablyFairService::calculateRoll($serverSeed, $clientSeed, $nonce, 0);

                if ($hasTier0) {
                    if ($roll < 0.40) $tier = 0;
                    elseif ($roll < 0.80) $tier = 1;
                    elseif ($roll < 0.96) $tier = 2;
                    elseif ($roll < 0.991) $tier = 3;
                    elseif ($roll < 0.9974) $tier = 4;
                    else $tier = 5;
                } else {
                    $tier = ProvablyFairService::rarityFromRoll($roll);
                }

                $skinsInTier = $tierMap[$tier] ?? [];
                while (!$skinsInTier && $tier > 0) {
                    $tier--;
                    $skinsInTier = $tierMap[$tier] ?? [];
                }
                if (!$skinsInTier) {
                    foreach ([1,2,3,4,5,0] as $fallbackTier) {
                        if (!empty($tierMap[$fallbackTier])) {
                            $skinsInTier = $tierMap[$fallbackTier];
                            $tier = $fallbackTier;
                            break;
                        }
                    }
                }

                $pickHash = ProvablyFairService::calculateRoll($serverSeed, $clientSeed, $nonce, 99);
                $chosen = $skinsInTier[(int)floor($pickHash * count($skinsInTier)) % count($skinsInTier)];

                [$isSt, $rawFloat] = ProvablyFairService::calculateAttributes($serverSeed, $clientSeed, $nonce, 0);
                $actualFloat = round((float)$chosen['min_float'] + $rawFloat * ((float)$chosen['max_float'] - (float)$chosen['min_float']), 6);
                [$wearName, $wearMultiplier] = ProvablyFairService::wear($actualFloat);
                $value = round((float)$chosen['base_price'] * $wearMultiplier * ($isSt ? 2.2 : 1.0), 2);
                $totalPayout += $value;

                $insInv = $pdo->prepare('
                    INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ');
                $insInv->execute([$user['id'], $chosen['id'], $actualFloat, $wearName, $isSt ? 1 : 0, $value]);
                $inventoryId = (int)$pdo->lastInsertId();

                $insHistory = $pdo->prepare('
                    INSERT INTO open_history
                    (user_id, case_id, inventory_id, cost, payout, server_seed, server_seed_hash, client_seed, nonce, roll_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $insHistory->execute([
                    $user['id'], $caseId, $inventoryId, $unitCost, $value,
                    $serverSeed, $serverHash, $clientSeed, $nonce, round($roll, 6)
                ]);

                $drops[] = [
                    'inventory_id' => $inventoryId,
                    'skin_id' => $chosen['id'],
                    'weapon' => $chosen['weapon'],
                    'skin_name' => $chosen['skin_name'],
                    'rarity' => $chosen['rarity'],
                    'rarity_name' => $chosen['rarity_name'],
                    'rarity_color' => $chosen['rarity_color'],
                    'rarity_tier' => $tier,
                    'image' => $chosen['image'],
                    'float_value' => $actualFloat,
                    'wear_name' => $wearName,
                    'is_stattrak' => (bool)$isSt,
                    'value' => $value,
                    'server_seed_hash' => $serverHash,
                    'server_seed_revealed' => $serverSeed,
                    'client_seed' => $clientSeed,
                    'nonce' => $nonce,
                    'roll_number' => round($roll, 6),
                ];
            }

            $newBalance = round((float)$u['balance'] - $totalCost, 2);
            $updateBalance = $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?');
            $updateBalance->execute([$newBalance, $user['id']]);

            $ledger = $pdo->prepare('
                INSERT INTO transactions (user_id, type, amount, balance_after, description)
                VALUES (?, "case_open", ?, ?, ?)
            ');
            $ledger->execute([$user['id'], -$totalCost, $newBalance, "Opened {$count}x {$case['name']}"]);

            return [
                'success' => true,
                'drops' => $drops,
                'spent' => $totalCost,
                'remaining_balance' => $newBalance,
                'server_seed_hash' => $serverHash,
            ];
        });

        $totalPayout = array_reduce($result['drops'], fn(float $sum, array $d): float => $sum + (float)$d['value'], 0.0);
        Logger::audit(sprintf(
            'CASE_OPEN user=%s id=%d case=%s count=%d spent=%.2f payout=%.2f ip=%s',
            $user['username'], $user['id'], $caseId, $count, $result['spent'], $totalPayout, Request::clientIp()
        ));
        Logger::security('CASE_OPEN', ['user_id' => $user['id'], 'case_id' => $caseId, 'count' => $count]);

        return $result;
    }

    public static function verifyRoll(array $input): array
    {
        $serverSeed = (string)($input['server_seed'] ?? '');
        $clientSeed = (string)($input['client_seed'] ?? '');
        $nonce = (int)($input['nonce'] ?? 0);
        $subIndex = (int)($input['sub_index'] ?? 0);

        if ($serverSeed === '' || $clientSeed === '' || $nonce < 0) {
            Response::error('Invalid verification parameters', 400);
        }

        $roll = ProvablyFairService::calculateRoll($serverSeed, $clientSeed, $nonce, $subIndex);
        [$isSt, $rawFloat] = ProvablyFairService::calculateAttributes($serverSeed, $clientSeed, $nonce, $subIndex);
        [$wearName] = ProvablyFairService::wear($rawFloat);
        $tier = ProvablyFairService::rarityFromRoll($roll);

        $tierNames = [
            1 => 'Mil-Spec Grade (Blue ~79.92%)',
            2 => 'Restricted (Purple ~15.98%)',
            3 => 'Classified (Pink ~3.20%)',
            4 => 'Covert (Red ~0.64%)',
            5 => '★ Special Rare (Gold Knife/Glove ~0.26%)',
        ];

        return [
            'verified' => true,
            'server_seed' => $serverSeed,
            'server_seed_hash' => ProvablyFairService::hashServerSeed($serverSeed),
            'client_seed' => $clientSeed,
            'nonce' => $nonce,
            'roll_number' => round($roll, 8),
            'rarity_tier' => $tier,
            'rarity_desc' => $tierNames[$tier] ?? 'Unknown',
            'is_stattrak' => $isSt,
            'raw_float' => round($rawFloat, 6),
            'wear_condition' => $wearName,
        ];
    }
}
