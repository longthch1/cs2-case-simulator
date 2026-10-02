<?php
/**
 * CS2 Case Opening Simulator — Cases API
 * Routes:
 *   GET  /api/cases
 *   GET  /api/cases/{id}
 *   POST /api/cases/{id}/open
 *   POST /api/cases/verify-roll
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';

setCorsHeaders();

$method = getMethod();
$path   = getApiPath('cases');

// ── OPTIONS Pre-flight ─────────────────────────────────────────────────────────
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── POST /api/cases/verify-roll ───────────────────────────────────────────────
if ($method === 'POST' && $path === 'verify-roll') {
    handleVerifyRoll();
}

// ── GET /api/cases ────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '') {
    handleListCases();
}

// ── GET /api/cases/{id} ───────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^([^/]+)$#', $path, $m)) {
    handleGetCase($m[1]);
}

// ── POST /api/cases/{id}/open ─────────────────────────────────────────────────
if ($method === 'POST' && preg_match('#^([^/]+)/open$#', $path, $m)) {
    handleOpenCase($m[1]);
}

jsonError('Endpoint not found', 404);

// ==============================================================================
// Handlers
// ==============================================================================

function handleListCases(): void
{
    $db = getDB();
    $stmt = $db->query(
        'SELECT c.id, c.name, c.description, c.price, c.key_price, c.image,
                COUNT(s.id) AS total_items
         FROM cases c
         LEFT JOIN skins s ON c.id = s.case_id
         WHERE c.is_active = 1
         GROUP BY c.id
         ORDER BY c.price ASC'
    );
    $cases = $stmt->fetchAll();

    foreach ($cases as &$c) {
        $c['price']       = (float)$c['price'];
        $c['key_price']   = (float)$c['key_price'];
        $c['total_items'] = (int)$c['total_items'];
    }
    unset($c);

    jsonResponse(['cases' => $cases]);
}

function handleGetCase(string $caseId): void
{
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, description, price, key_price, image FROM cases WHERE id = ? AND is_active = 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();

    if (!$case) {
        jsonError('Case not found', 404);
    }

    $case['price']     = (float)$case['price'];
    $case['key_price'] = (float)$case['key_price'];

    $stmt = $db->prepare(
        'SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
         FROM skins
         WHERE case_id = ?
         ORDER BY rarity_tier ASC, base_price ASC'
    );
    $stmt->execute([$caseId]);
    $skins = $stmt->fetchAll();

    foreach ($skins as &$s) {
        $s['rarity_tier'] = (int)$s['rarity_tier'];
        $s['base_price']  = (float)$s['base_price'];
        $s['min_float']   = (float)$s['min_float'];
        $s['max_float']   = (float)$s['max_float'];
    }
    unset($s);

    jsonResponse([
        'case'        => $case,
        'total_price' => round($case['price'] + $case['key_price'], 2),
        'skins'       => $skins
    ]);
}

function handleOpenCase(string $caseId): void
{
    $user = requireAuth();
    $userId = (int)$user['id'];

    if (isRateLimited("open_{$userId}", 30, 60)) {
        jsonError('Opening too fast. Please slow down.', 429);
    }

    $body       = getRequestBody();
    $count      = (int)($body['count'] ?? 1);
    $clientSeed = trim((string)($body['client_seed'] ?? bin2hex(random_bytes(16))));

    if (!in_array($count, [1, 2, 3, 5, 10], true)) {
        jsonError('Invalid open count. Allowed: 1, 2, 3, 5, 10.', 400);
    }

    $db = getDB();
    $db->beginTransaction();

    try {
        // 1. Fetch Case info
        $stmt = $db->prepare('SELECT id, name, price, key_price FROM cases WHERE id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();

        if (!$case) {
            $db->rollBack();
            jsonError('Case not found', 404);
        }

        $unitCost  = (float)$case['price'] + (float)$case['key_price'];
        $totalCost = round($unitCost * $count, 2);

        // 2. Check and lock User balance
        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        if (!$userRow || (float)$userRow['balance'] < $totalCost) {
            $db->rollBack();
            $currBal = $userRow ? (float)$userRow['balance'] : 0.0;
            jsonError(
                sprintf('Số dư không đủ. Mở %d rương cần $%.2f, số dư hiện có: $%.2f.', $count, $totalCost, $currBal),
                400
            );
        }

        // 3. Fetch skins grouped by rarity tier
        $stmt = $db->prepare(
            'SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
             FROM skins WHERE case_id = ?'
        );
        $stmt->execute([$caseId]);
        $allSkins = $stmt->fetchAll();

        if (empty($allSkins)) {
            $db->rollBack();
            jsonError('Rương này chưa có skin nào.', 500);
        }

        $tierMap = [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
        foreach ($allSkins as $s) {
            $t = (int)($s['rarity_tier'] ?? 1);
            $tierMap[isset($tierMap[$t]) ? $t : 1][] = $s;
        }

        // 4. Base Nonce
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM open_history WHERE user_id = ?');
        $stmt->execute([$userId]);
        $baseNonce = (int)$stmt->fetch()['cnt'];

        $dropsResult = [];
        $totalPayout = 0.0;
        $serverSeed  = ProvablyFairRNG::generateServerSeed();
        $serverSeedHash = ProvablyFairRNG::hashServerSeed($serverSeed);
        $hasTier0    = count($tierMap[0]) > 0;

        for ($i = 0; $i < $count; $i++) {
            $nonce = $baseNonce + $i + 1;
            $roll  = ProvablyFairRNG::calculateRoll($serverSeed, $clientSeed, $nonce);

            if ($hasTier0) {
                if ($roll < 0.40)      $tier = 0;
                elseif ($roll < 0.80)  $tier = 1;
                elseif ($roll < 0.96)  $tier = 2;
                elseif ($roll < 0.991) $tier = 3;
                elseif ($roll < 0.9974) $tier = 4;
                else                    $tier = 5;
            } else {
                $tier = ProvablyFairRNG::getRarityTierFromRoll($roll);
            }

            // Fallback if tier has no skins
            $skinsInTier = $tierMap[$tier] ?? [];
            while (empty($skinsInTier) && $tier > 0) {
                $tier--;
                $skinsInTier = $tierMap[$tier] ?? [];
            }
            if (empty($skinsInTier)) {
                foreach ([1, 2, 3, 4, 5, 0] as $t) {
                    if (!empty($tierMap[$t])) {
                        $skinsInTier = $tierMap[$t];
                        $tier = $t;
                        break;
                    }
                }
            }

            // Pick skin deterministically
            $pickHash   = ProvablyFairRNG::calculateRoll($serverSeed, $clientSeed, $nonce, 99);
            $chosenSkin = $skinsInTier[(int)floor($pickHash * count($skinsInTier)) % count($skinsInTier)];

            // StatTrak + Wear Float
            [$isSt, $rawFloat] = ProvablyFairRNG::calculateStatTrakAndFloat($serverSeed, $clientSeed, $nonce);
            $minF = (float)$chosenSkin['min_float'];
            $maxF = (float)$chosenSkin['max_float'];
            $actualFloat = round($minF + $rawFloat * ($maxF - $minF), 6);

            [$wearName, $wearMult] = ProvablyFairRNG::getWearCondition($actualFloat);
            $stMult    = $isSt ? 2.2 : 1.0;
            $itemValue = round((float)$chosenSkin['base_price'] * $wearMult * $stMult, 2);
            $totalPayout += $itemValue;

            // Insert into inventory
            $stmtInv = $db->prepare(
                'INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $stmtInv->execute([$userId, $chosenSkin['id'], $actualFloat, $wearName, $isSt ? 1 : 0, $itemValue]);
            $inventoryId = (int)$db->lastInsertId();

            // Insert into open_history
            $stmtHist = $db->prepare(
                'INSERT INTO open_history (user_id, case_id, inventory_id, cost, payout, server_seed, server_seed_hash, client_seed, nonce, roll_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtHist->execute([
                $userId, $caseId, $inventoryId, $unitCost, $itemValue,
                $serverSeed, $serverSeedHash, $clientSeed, $nonce, round($roll, 6)
            ]);

            $dropsResult[] = [
                'inventory_id'         => $inventoryId,
                'skin_id'              => $chosenSkin['id'],
                'weapon'               => $chosenSkin['weapon'],
                'skin_name'            => $chosenSkin['skin_name'],
                'rarity'               => $chosenSkin['rarity'],
                'rarity_name'          => $chosenSkin['rarity_name'],
                'rarity_color'         => $chosenSkin['rarity_color'],
                'rarity_tier'          => $tier,
                'image'                => $chosenSkin['image'],
                'float_value'          => $actualFloat,
                'wear_name'            => $wearName,
                'is_stattrak'          => $isSt,
                'value'                => $itemValue,
                'server_seed_hash'     => $serverSeedHash,
                'server_seed_revealed' => $serverSeed,
                'client_seed'          => $clientSeed,
                'nonce'                => $nonce,
                'roll_number'          => round($roll, 6)
            ];
        }

        // 5. Deduct Balance & Log Transaction
        $newBalance = round((float)$userRow['balance'] - $totalCost, 2);
        $db->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$newBalance, $userId]);

        $db->prepare(
            "INSERT INTO transactions (user_id, type, amount, balance_after, description)
             VALUES (?, 'case_open', ?, ?, ?)"
        )->execute([$userId, -$totalCost, $newBalance, "Opened {$count}x {$case['name']}"]);

        $db->commit();

        $ip = getClientIp();
        logAudit($userId, 'CASE_OPEN', $ip, "Opened {$count}x {$case['name']} for \${$totalCost}");

        jsonResponse([
            'success'           => true,
            'drops'             => $dropsResult,
            'spent'             => $totalCost,
            'remaining_balance' => $newBalance,
            'server_seed_hash'  => $serverSeedHash
        ]);

    } catch (Throwable $e) {
        $db->rollBack();
        writeLog('ERROR', 'Case open failed: ' . $e->getMessage());
        jsonError('Đã xảy ra lỗi khi mở rương: ' . $e->getMessage(), 500);
    }
}

function handleVerifyRoll(): void
{
    $body       = getRequestBody();
    $serverSeed = (string)($body['server_seed'] ?? '');
    $clientSeed = (string)($body['client_seed'] ?? '');
    $nonce      = (int)($body['nonce'] ?? 0);
    $subIndex   = (int)($body['sub_index'] ?? 0);

    if (empty($serverSeed) || empty($clientSeed)) {
        jsonError('server_seed và client_seed không được để trống.', 400);
    }

    $roll = ProvablyFairRNG::calculateRoll($serverSeed, $clientSeed, $nonce, $subIndex);
    $tier = ProvablyFairRNG::getRarityTierFromRoll($roll);
    [$isSt, $rawFloat] = ProvablyFairRNG::calculateStatTrakAndFloat($serverSeed, $clientSeed, $nonce);
    [$wearName, ] = ProvablyFairRNG::getWearCondition($rawFloat);
    $serverSeedHash = ProvablyFairRNG::hashServerSeed($serverSeed);

    $tierNames = [
        1 => 'Mil-Spec Grade (Blue ~79.92%)',
        2 => 'Restricted (Purple ~15.98%)',
        3 => 'Classified (Pink ~3.20%)',
        4 => 'Covert (Red ~0.64%)',
        5 => '★ Special Rare (Gold Knife/Glove ~0.26%)'
    ];

    jsonResponse([
        'verified'         => true,
        'server_seed'      => $serverSeed,
        'server_seed_hash' => $serverSeedHash,
        'client_seed'      => $clientSeed,
        'nonce'            => $nonce,
        'roll_number'      => round($roll, 8),
        'rarity_tier'      => $tier,
        'rarity_desc'      => $tierNames[$tier] ?? 'Unknown',
        'is_stattrak'      => $isSt,
        'raw_float'        => round($rawFloat, 6),
        'wear_condition'   => $wearName
    ]);
}
