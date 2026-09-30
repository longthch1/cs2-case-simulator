<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CS2\Security\ProvablyFairService;

$vectors = [
    [
        'server_seed' => str_repeat('a', 64),
        'client_seed' => 'client-seed',
        'nonce' => 1,
        'sub_index' => 0,
        'roll' => 0.27897504554130137,
        'stattrak' => false,
        'raw_float' => 0.9473623137455434,
        'server_hash' => 'ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb',
    ],
    [
        'server_seed' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'client_seed' => 'LongTest',
        'nonce' => 100,
        'sub_index' => 0,
        'roll' => 0.710007709916681,
        'stattrak' => false,
        'raw_float' => 0.6160169045906514,
        'server_hash' => 'a8ae6e6ee929abea3afcfc5258c8ccd6f85273e0d4626d26c7279f3250f77c8e',
    ],
    [
        'server_seed' => str_repeat('deadbeef', 8),
        'client_seed' => 'xyz',
        'nonce' => 42,
        'sub_index' => 99,
        'roll' => 0.3274090006016195,
        'stattrak' => false,
        'raw_float' => 0.639654862228781,
        'server_hash' => '247d08f3e13938b244f5ecd8966f1778e5e72b175820f46ba86c9c039272affa',
    ],
];

$epsilon = 1e-15;

foreach ($vectors as $i => $v) {
    $roll = ProvablyFairService::calculateRoll($v['server_seed'], $v['client_seed'], $v['nonce'], $v['sub_index']);
    [$st, $raw] = ProvablyFairService::calculateAttributes($v['server_seed'], $v['client_seed'], $v['nonce'], $v['sub_index']);
    $hash = ProvablyFairService::hashServerSeed($v['server_seed']);

    if (abs($roll - $v['roll']) > $epsilon) throw new RuntimeException("roll mismatch at vector " . ($i + 1));
    if ($st !== $v['stattrak']) throw new RuntimeException("stattrak mismatch at vector " . ($i + 1));
    if (abs($raw - $v['raw_float']) > $epsilon) throw new RuntimeException("float mismatch at vector " . ($i + 1));
    if (!hash_equals($hash, $v['server_hash'])) throw new RuntimeException("hash mismatch at vector " . ($i + 1));
}

echo "Provably Fair vectors: PASS (" . count($vectors) . " vectors)\n";
