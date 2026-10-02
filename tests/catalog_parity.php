<?php
declare(strict_types=1);

$catalog = require __DIR__ . '/../database/catalog.php';
if (!is_array($catalog)) {
    fwrite(STDERR, "Catalog did not return an array.\n");
    exit(1);
}

$caseCount = count($catalog);
$itemCount = 0;
$uniqueItemIds = [];

foreach ($catalog as $caseIndex => $case) {
    foreach (['id', 'name', 'items'] as $key) {
        if (!array_key_exists($key, $case)) {
            fwrite(STDERR, "Case {$caseIndex} missing required field: {$key}\n");
            exit(1);
        }
    }

    if (!is_array($case['items'])) {
        fwrite(STDERR, "Case {$caseIndex} items must be an array.\n");
        exit(1);
    }

    if ((float)$case['price'] < 5.0 || (float)$case['price'] > 10.0) {
        fwrite(STDERR, "Case {$case['id']} price is outside requested $5-$10 range: {$case['price']}\n");
        exit(1);
    }

    foreach ($case['items'] as $itemIndex => $item) {
        foreach (['id', 'fullName', 'weapon', 'skin', 'rarity', 'name', 'color', 'tier', 'price', 'min_float', 'max_float', 'image'] as $key) {
            if (!array_key_exists($key, $item)) {
                fwrite(STDERR, "Case {$case['id']} item {$itemIndex} missing {$key}\n");
                exit(1);
            }
        }

        $rarityMap = [
            'mil-spec' => ['name' => 'Mil-Spec Grade', 'color' => '#4b69ff', 'tier' => 1],
            'restricted' => ['name' => 'Restricted', 'color' => '#8847ff', 'tier' => 2],
            'classified' => ['name' => 'Classified', 'color' => '#d32ce6', 'tier' => 3],
            'covert' => ['name' => 'Covert', 'color' => '#eb4b4b', 'tier' => 4],
            'special' => ['name' => '★ Special Rare', 'color' => '#ffd700', 'tier' => 5],
        ];
        if (!isset($rarityMap[$item['rarity']])) {
            fwrite(STDERR, "Case {$case['id']} item {$itemIndex} has unknown rarity {$item['rarity']}\n");
            exit(1);
        }
        $expectedRarity = $rarityMap[$item['rarity']];
        if ($item['name'] !== $expectedRarity['name'] || $item['color'] !== $expectedRarity['color'] || (int)$item['tier'] !== $expectedRarity['tier']) {
            fwrite(STDERR, "Case {$case['id']} item {$itemIndex} has inconsistent rarity metadata.\n");
            exit(1);
        }

        if ((float)$item['price'] < 0 || (float)$item['min_float'] > (float)$item['max_float']) {
            fwrite(STDERR, "Case {$case['id']} item {$itemIndex} has invalid price/float metadata.\n");
            exit(1);
        }

        $itemCount++;
        $uniqueKey = $case['id'] . ':' . $item['id'];
        $uniqueItemIds[$uniqueKey] = true;
    }
}

$uniqueCount = count($uniqueItemIds);
$expectedCases = 42;
$expectedSourceItems = 1161;
$expectedUniqueItems = 1093;

$expectedSamples = [
    'crate-4001' => 10.00,
    'crate-4001_mp7_skulls' => ['price' => 1.32, 'tier' => 1],
    'crate-4001_ak-47_case_hardened' => ['price' => 606.96, 'tier' => 3],
    'crate-7007_awp_printstream' => ['price' => 337.28, 'tier' => 4],
];
foreach ($expectedSamples as $key => $expected) {
    if ($key === 'crate-4001') {
        $match = array_values(array_filter($catalog, fn(array $case): bool => $case['id'] === $key));
        if (!$match || abs((float)$match[0]['price'] - $expected) > 0.0001) {
            fwrite(STDERR, "Case {$key} price parity check failed.\n");
            exit(1);
        }
        continue;
    }
    $found = null;
    foreach ($catalog as $case) {
        foreach ($case['items'] as $item) {
            if ($item['id'] === $key) {
                $found = $item;
                break 2;
            }
        }
    }
    if ($found === null || abs((float)$found['price'] - $expected['price']) > 0.0001 || (int)$found['tier'] !== $expected['tier']) {
        fwrite(STDERR, "Catalog sample parity check failed for {$key}.\n");
        exit(1);
    }
}

if ($caseCount !== $expectedCases || $itemCount !== $expectedSourceItems || $uniqueCount !== $expectedUniqueItems) {
    fwrite(
        STDERR,
        sprintf(
            "Catalog parity failed: cases=%d/%d source_items=%d/%d unique_items=%d/%d\n",
            $caseCount,
            $expectedCases,
            $itemCount,
            $expectedSourceItems,
            $uniqueCount,
            $expectedUniqueItems
        )
    );
    exit(1);
}

echo sprintf(
    "Catalog parity OK: %d cases, %d source item entries, %d unique catalog items.\n",
    $caseCount,
    $itemCount,
    $uniqueCount
);
