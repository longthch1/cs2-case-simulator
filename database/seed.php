<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CS2\Config\Config;
use CS2\Database\Database;
use CS2\Utils\Logger;

/*
 * The legacy FastAPI implementation kept its authoritative catalog in
 * app/seed_data.py (PRESET_CASES). The PHP port uses database/catalog.php,
 * generated from that exact dataset, so the migration keeps every original
 * case and skin instead of the smaller curated cases-data.js subset.
 */
$catalogFile = __DIR__ . '/catalog.php';
if (!is_file($catalogFile)) {
    fwrite(STDERR, "database/catalog.php not found.\n");
    exit(1);
}

$data = require $catalogFile;
if (!is_array($data)) {
    fwrite(STDERR, "database/catalog.php did not return a valid catalog array.\n");
    exit(1);
}

$caseCount = 0;
$itemCount = 0;
$uniqueItemCount = 0;

$pdo = Database::connection();

Database::transaction(function($pdo) use ($data, &$caseCount, &$itemCount, &$uniqueItemCount): void {
    $caseStmt = $pdo->prepare(
        'INSERT INTO cases (id,name,description,price,key_price,image,is_active)
         VALUES (?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description),
         price=VALUES(price), key_price=VALUES(key_price), image=VALUES(image),
         is_active=1'
    );

    $skinStmt = $pdo->prepare(
        'INSERT INTO skins
         (id,case_id,name,weapon,skin_name,rarity,rarity_name,rarity_color,rarity_tier,image,base_price,min_float,max_float,can_be_stattrak)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE case_id=VALUES(case_id), name=VALUES(name),
         weapon=VALUES(weapon), skin_name=VALUES(skin_name), rarity=VALUES(rarity),
         rarity_name=VALUES(rarity_name), rarity_color=VALUES(rarity_color),
         rarity_tier=VALUES(rarity_tier), image=VALUES(image), base_price=VALUES(base_price),
         min_float=VALUES(min_float), max_float=VALUES(max_float),
         can_be_stattrak=VALUES(can_be_stattrak)'
    );

    $seen = [];
    foreach ($data as $case) {
        if (!isset($case['id'], $case['name'])) {
            continue;
        }

        $caseId = (string)$case['id'];
        $caseStmt->execute([
            $caseId,
            (string)$case['name'],
            $case['description'] ?? '',
            (float)($case['price'] ?? 0),
            (float)($case['key_price'] ?? 2.49),
            (string)($case['image'] ?? '')
        ]);

        $caseCount++;

        foreach (($case['items'] ?? []) as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            /*
             * The original Python/SQLite schema used skin.id as a primary key
             * and INSERT OR REPLACE, so repeated entries with the same id were
             * effectively one catalog row. Preserve that behavior in MySQL.
             */
            $skinId = (string)$item['id'];
            $seenKey = $caseId . ':' . $skinId;
            if (!isset($seen[$seenKey])) {
                $uniqueItemCount++;
                $seen[$seenKey] = true;
            }

            $skinStmt->execute([
                $skinId,
                $caseId,
                (string)($item['fullName'] ?? (($item['weapon'] ?? '') . ' | ' . ($item['skin'] ?? ''))),
                (string)($item['weapon'] ?? ''),
                (string)($item['skin'] ?? ''),
                (string)($item['rarity'] ?? ''),
                (string)($item['rarityName'] ?? ''),
                (string)($item['rarityColor'] ?? '#4b69ff'),
                (int)($item['rarityTier'] ?? 1),
                (string)($item['image'] ?? ''),
                (float)($item['basePrice'] ?? 0),
                (float)($item['minFloat'] ?? 0),
                (float)($item['maxFloat'] ?? 1),
                !empty($item['canBeStatTrak']) ? 1 : 0
            ]);

            $itemCount++;
        }
    }
});

$adminPassword = Config::adminPassword();
if ($adminPassword !== '') {
    $hash = password_hash($adminPassword, PASSWORD_ARGON2ID);
    if ($hash === false) {
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    }

    $pdo->prepare(
        'INSERT INTO users (username,email,password_hash,role,balance)
         VALUES (?,?,?,"admin",?)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),
         role="admin", email=VALUES(email)'
    )->execute([
        Config::adminUsername(),
        Config::adminEmail(),
        $hash,
        Config::adminStartingBalance()
    ]);
}

Logger::info("Database seed completed: {$caseCount} cases and {$uniqueItemCount} unique catalog items ({$itemCount} source entries).");
echo "Seed complete: {$caseCount} cases, {$uniqueItemCount} unique catalog items, {$itemCount} source entries.\n";
