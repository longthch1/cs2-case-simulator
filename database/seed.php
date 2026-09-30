<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CS2\Config\Config;
use CS2\Database\Database;
use CS2\Utils\Logger;

$dataFile = __DIR__ . '/../cases-data.js';
if (!is_file($dataFile)) {
    fwrite(STDERR, "cases-data.js not found.\n");
    exit(1);
}

$raw = file_get_contents($dataFile);
$start = strpos($raw, '[');
$end = strrpos($raw, '];');
if ($start === false || $end === false) {
    fwrite(STDERR, "Unable to parse cases-data.js.\n");
    exit(1);
}

$data = json_decode(substr($raw, $start, $end - $start + 1), true);
if (!is_array($data)) {
    fwrite(STDERR, "cases-data.js does not contain valid JSON data.\n");
    exit(1);
}

$pdo = Database::connection();

$caseStmt = $pdo->prepare(
    'INSERT INTO cases (id,name,description,price,key_price,image,is_active)
     VALUES (?,?,?,?,?,?,1)
     ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description),
     price=VALUES(price), key_price=VALUES(key_price), image=VALUES(image)'
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

Database::transaction(function($pdo) use ($data, $caseStmt, $skinStmt): void {
    foreach ($data as $case) {
        if (!isset($case['id'], $case['name'])) continue;

        $caseStmt->execute([
            (string)$case['id'],
            (string)$case['name'],
            $case['description'] ?? '',
            (float)($case['price'] ?? 0),
            (float)($case['keyPrice'] ?? 2.49),
            (string)($case['image'] ?? '')
        ]);

        foreach (($case['items'] ?? []) as $item) {
            if (!isset($item['id'])) continue;

            $skinStmt->execute([
                (string)$item['id'],
                (string)$case['id'],
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
        }
    }
});

$adminPassword = Config::adminPassword();
if ($adminPassword === '') {
    echo "Catalog seeded. ADMIN_PASSWORD is empty, so no admin account was created.\n";
    exit(0);
}

$hash = password_hash($adminPassword, PASSWORD_ARGON2ID);
if ($hash === false) $hash = password_hash($adminPassword, PASSWORD_DEFAULT);

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

Logger::info('Database seed completed: ' . count($data) . ' cases.');
echo 'Seed complete: ' . count($data) . " cases.\n";
