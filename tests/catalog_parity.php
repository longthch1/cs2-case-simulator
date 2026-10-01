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

    foreach ($case['items'] as $itemIndex => $item) {
        foreach (['id', 'fullName', 'weapon', 'skin', 'rarity', 'name', 'color', 'tier', 'price', 'min_float', 'max_float', 'image'] as $key) {
            if (!array_key_exists($key, $item)) {
                fwrite(STDERR, "Case {$case['id']} item {$itemIndex} missing {$key}\n");
                exit(1);
            }
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
