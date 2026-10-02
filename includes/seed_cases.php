<?php
/**
 * CS2 Case Opening Simulator - Case & Skin Seeder
 *
 * Standalone script that seeds:
 *  - Admin user (if not exists)
 *  - Top 10 CS2 cases with Steam CDN images
 *  - 5–10 sample skins per case across all rarities
 *
 * Run once from CLI or browser after schema has been applied.
 *
 * Usage (CLI):
 *   php includes/seed_cases.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
$root = dirname(__DIR__);
$configFile = $root . '/config/database.php';

if (file_exists($configFile)) {
    $dbConfig = require $configFile;
} else {
    // Fallback defaults – override via config/database.php
    $dbConfig = [
        'host'    => '127.0.0.1',
        'port'    => '3306',
        'dbname'  => 'cs2_simulator',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ];
}

// ── PDO connection ─────────────────────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname'],
        $dbConfig['charset']
    );
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage() . PHP_EOL);
}

// ── Helper ─────────────────────────────────────────────────────────────────
function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}" . PHP_EOL;
}

// ── 1. Admin user ──────────────────────────────────────────────────────────
log_msg('Seeding admin user…');

$adminHash = password_hash('admin', PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare(
    "INSERT INTO users (username, email, password_hash, role, balance)
     VALUES (:username, :email, :hash, 'admin', 999999.00)
     ON DUPLICATE KEY UPDATE role = 'admin', balance = 999999.00"
);
$stmt->execute([
    ':username' => 'admin',
    ':email'    => 'admin@cs2simulator.local',
    ':hash'     => $adminHash,
]);

log_msg('Admin user ready.');

// ── 2. Case & Skin data ────────────────────────────────────────────────────
/**
 * Rarity reference:
 *  tier 1 = Consumer   (#b0c3d9)
 *  tier 2 = Industrial (#5e98d9)
 *  tier 3 = Mil-Spec   (#4b69ff)
 *  tier 4 = Restricted (#8847ff)
 *  tier 5 = Classified (#d32ce6)
 *  tier 6 = Covert     (#eb4b4b)
 *  tier 7 = Rare (★)   (#e4ae39)
 */

$steamBase = 'https://community.akamai.steamstatic.com/economy/image/';

// placeholder skin image (grey square via Steam CDN class)
$skinPlaceholder = 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frnBV-Sb8asUoCRSzjWwjk5EdiV1jmhh5xtmGLnqmdiqVOXiuOelxvlmHBHdwFvHqe0sRq0';

$cases = [

    // ── 1. CS:GO Weapon Case ────────────────────────────────────────────────
    [
        'id'          => 'csgo_weapon_case',
        'name'        => 'CS:GO Weapon Case',
        'description' => 'The original CS:GO weapon case containing classic community designs.',
        'price'       => 2.50,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bjz4VWyRAFgzlFNb3AQV4KPHMb_LtjpJb7EeDeXqC2GGvEEYwYGZBz0gDhkh9nAkp1A',
        'skins'       => [
            [
                'id'          => 'csgo_wc_awp_lightning_strike',
                'weapon'      => 'AWP',
                'skin_name'   => 'Lightning Strike',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 42.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_m4a1s_blood_tiger',
                'weapon'      => 'M4A1-S',
                'skin_name'   => 'Blood Tiger',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 18.00,
                'min_float'   => 0.00,
                'max_float'   => 0.40,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_ak47_case_hardened',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Case Hardened',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 22.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_deagle_hypnotic',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Hypnotic',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 9.50,
                'min_float'   => 0.00,
                'max_float'   => 0.70,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_glock_groundwater',
                'weapon'      => 'Glock-18',
                'skin_name'   => 'Groundwater',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.20,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_famas_doomkitty',
                'weapon'      => 'FAMAS',
                'skin_name'   => 'Doomkitty',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 5.80,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_p250_splash',
                'weapon'      => 'P250',
                'skin_name'   => 'Splash',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 0.80,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'csgo_wc_bayonet_vanilla',
                'weapon'      => 'Bayonet',
                'skin_name'   => '★ Vanilla',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 190.00,
                'min_float'   => 0.06,
                'max_float'   => 0.80,
                'can_stattrak'=> 0,
            ],
        ],
    ],

    // ── 2. Operation Bravo Case ─────────────────────────────────────────────
    [
        'id'          => 'operation_bravo_case',
        'name'        => 'Operation Bravo Case',
        'description' => 'Dropped during CS:GO\'s Operation Bravo, featuring clean iconic weapon skins.',
        'price'       => 4.50,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bjz4VmxRABwzReRZ2AQl4KPWMb_LljZJb7VeVeHqC2GHqEEYwYGJBk1gGgkhdnMkq1A',
        'skins'       => [
            [
                'id'          => 'bravo_awp_asiimov',
                'weapon'      => 'AWP',
                'skin_name'   => 'Asiimov',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 65.00,
                'min_float'   => 0.18,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'bravo_m4a4_asiimov',
                'weapon'      => 'M4A4',
                'skin_name'   => 'Asiimov',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 45.00,
                'min_float'   => 0.18,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'bravo_ak47_fire_serpent',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Fire Serpent',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 320.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'bravo_p90_cold_blooded',
                'weapon'      => 'P90',
                'skin_name'   => 'Cold Blooded',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 6.50,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'bravo_mp9_storm',
                'weapon'      => 'MP9',
                'skin_name'   => 'Storm',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 2.40,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'bravo_karambit_vanilla',
                'weapon'      => 'Karambit',
                'skin_name'   => '★ Vanilla',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 480.00,
                'min_float'   => 0.06,
                'max_float'   => 0.80,
                'can_stattrak'=> 0,
            ],
        ],
    ],

    // ── 3. Kilowatt Case ────────────────────────────────────────────────────
    [
        'id'          => 'kilowatt_case',
        'name'        => 'Kilowatt Case',
        'description' => 'A high-voltage case packed with electric designs and neon energy.',
        'price'       => 8.00,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frnEVvqf_a6VoIfGSXz7Hlbwg57QwSS_mxhl15jiGyN37c3_GZw91W8BwRflK7EfKsa2sfw',
        'skins'       => [
            [
                'id'          => 'kilowatt_m4a1s_black_lotus',
                'weapon'      => 'M4A1-S',
                'skin_name'   => 'Black Lotus',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 85.00,
                'min_float'   => 0.00,
                'max_float'   => 0.50,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_awp_chrome_cannon',
                'weapon'      => 'AWP',
                'skin_name'   => 'Chrome Cannon',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 72.00,
                'min_float'   => 0.00,
                'max_float'   => 0.60,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_deagle_printstream',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Printstream',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 38.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_usp_jawbreaker',
                'weapon'      => 'USP-S',
                'skin_name'   => 'Jawbreaker',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 22.00,
                'min_float'   => 0.00,
                'max_float'   => 0.40,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_mac10_ultrabeam',
                'weapon'      => 'MAC-10',
                'skin_name'   => 'Ultrabeam',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 8.00,
                'min_float'   => 0.00,
                'max_float'   => 0.70,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_sg553_hypnotic',
                'weapon'      => 'SG 553',
                'skin_name'   => 'Hypnotic',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 2.10,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'kilowatt_kukri_marble_fade',
                'weapon'      => 'Kukri Knife',
                'skin_name'   => '★ Marble Fade',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 280.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 4. Recoil Case ──────────────────────────────────────────────────────
    [
        'id'          => 'recoil_case',
        'name'        => 'Recoil Case',
        'description' => 'A bold case featuring striking geometric designs and fiery color palettes.',
        'price'       => 7.00,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frnMVu6b-avA-JqSSCjSWwuhz47U9TCzlxh9yt2WGnNqgIi-fbgUkWMNxFPlK7EdIJF6a2Q',
        'skins'       => [
            [
                'id'          => 'recoil_ak47_ice_coaled',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Ice Coaled',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 55.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'recoil_m4a4_temukau',
                'weapon'      => 'M4A4',
                'skin_name'   => 'Temukau',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 48.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'recoil_deagle_polished',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Polished',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 15.00,
                'min_float'   => 0.00,
                'max_float'   => 0.70,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'recoil_p250_visions',
                'weapon'      => 'P250',
                'skin_name'   => 'Visions',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 4.50,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'recoil_mp5sd_necro_jr',
                'weapon'      => 'MP5-SD',
                'skin_name'   => 'Necro Jr.',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.50,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'recoil_stiletto_doppler',
                'weapon'      => 'Stiletto Knife',
                'skin_name'   => '★ Doppler',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 240.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 5. Revolution Case ──────────────────────────────────────────────────
    [
        'id'          => 'revolution_case',
        'name'        => 'Revolution Case',
        'description' => 'Featuring stunning art-nouveau and mechanized designs from community artists.',
        'price'       => 6.50,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frnAVvfb6aqduc_TFVjTCxbx05OU4S3jilE9w4DzRnImtIy2Sa1JzDJEhRPlK7EcO4U8gfA',
        'skins'       => [
            [
                'id'          => 'revolution_ak47_head_shot',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Head Shot',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 62.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'revolution_m4a1s_emphorosaur',
                'weapon'      => 'M4A1-S',
                'skin_name'   => 'Emphorosaur-S',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 54.00,
                'min_float'   => 0.00,
                'max_float'   => 0.80,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'revolution_mac10_monkeyflage',
                'weapon'      => 'MAC-10',
                'skin_name'   => 'Monkeyflage',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 12.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'revolution_galil_chatterbox',
                'weapon'      => 'Galil AR',
                'skin_name'   => 'Chatterbox',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 5.20,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'revolution_usp_ticket_to_hell',
                'weapon'      => 'USP-S',
                'skin_name'   => 'Ticket to Hell',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.80,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'revolution_talon_fade',
                'weapon'      => 'Talon Knife',
                'skin_name'   => '★ Fade',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 520.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 6. Dreams & Nightmares Case ─────────────────────────────────────────
    [
        'id'          => 'dreams_nightmares_case',
        'name'        => 'Dreams & Nightmares Case',
        'description' => 'Enter dreamlike and horrifying worlds with surreal weapon art from community designers.',
        'price'       => 6.00,
        'key_price'   => 2.49,
        'image'       => $skinPlaceholder,
        'skins'       => [
            [
                'id'          => 'dn_m4a4_temukau',
                'weapon'      => 'M4A4',
                'skin_name'   => 'In Living Color',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 35.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'dn_mp9_starwalk',
                'weapon'      => 'MP9',
                'skin_name'   => 'Starwalk',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 28.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'dn_ak47_horns',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Nightwish',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 14.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'dn_mp7_abyssal_apparition',
                'weapon'      => 'MP7',
                'skin_name'   => 'Abyssal Apparition',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 3.50,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'dn_glock_night_mare',
                'weapon'      => 'Glock-18',
                'skin_name'   => 'Night Mare',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.10,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'dn_skeleton_slaughter',
                'weapon'      => 'Skeleton Knife',
                'skin_name'   => '★ Slaughter',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 350.00,
                'min_float'   => 0.01,
                'max_float'   => 0.26,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 7. Fracture Case ────────────────────────────────────────────────────
    [
        'id'          => 'fracture_case',
        'name'        => 'Fracture Case',
        'description' => 'A shattered aesthetic case featuring comic-book style designs and vivid contrasts.',
        'price'       => 7.50,
        'key_price'   => 2.49,
        'image'       => $skinPlaceholder,
        'skins'       => [
            [
                'id'          => 'fracture_deagle_hand_cannon',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Hand Cannon',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 42.00,
                'min_float'   => 0.00,
                'max_float'   => 0.80,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fracture_ak47_magma',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Legion of Anubis',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 38.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fracture_m4a1s_printstream',
                'weapon'      => 'M4A1-S',
                'skin_name'   => 'Printstream',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 25.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fracture_cz75_circaetus',
                'weapon'      => 'CZ75-Auto',
                'skin_name'   => 'Circaetus',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 3.80,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fracture_p90_freight',
                'weapon'      => 'P90',
                'skin_name'   => 'Freight',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 0.90,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fracture_ursus_case_hardened',
                'weapon'      => 'Ursus Knife',
                'skin_name'   => '★ Case Hardened',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 210.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 8. Operation Riptide Case ───────────────────────────────────────────
    [
        'id'          => 'operation_riptide_case',
        'name'        => 'Operation Riptide Case',
        'description' => 'Nautical and tactical designs from Operation Riptide, featuring underwater-themed skins.',
        'price'       => 8.00,
        'key_price'   => 2.49,
        'image'       => $skinPlaceholder,
        'skins'       => [
            [
                'id'          => 'riptide_glock_sacrifice',
                'weapon'      => 'Glock-18',
                'skin_name'   => 'Sacrifice',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 30.00,
                'min_float'   => 0.00,
                'max_float'   => 0.60,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'riptide_m4a4_temukau',
                'weapon'      => 'M4A4',
                'skin_name'   => 'Temukau',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 25.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'riptide_awp_liberator',
                'weapon'      => 'AWP',
                'skin_name'   => 'Liberator',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 18.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'riptide_mp5_gauss',
                'weapon'      => 'MP5-SD',
                'skin_name'   => 'Gauss',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 4.20,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'riptide_fiveseven_nitro',
                'weapon'      => 'Five-SeveN',
                'skin_name'   => 'Nitro',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.30,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'riptide_paracord_tiger_tooth',
                'weapon'      => 'Paracord Knife',
                'skin_name'   => '★ Tiger Tooth',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 190.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 9. Gallery Case ─────────────────────────────────────────────────────
    [
        'id'          => 'gallery_case',
        'name'        => 'Gallery Case',
        'description' => 'An artistic exhibition of weapon finishes curated like a gallery of fine art.',
        'price'       => 9.00,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frnYVuPD5baE6IfTFCmSRme0j5eU5SXrjkRwmt2rWnoqhdnjEPQQiDpRxTflK7EePRV2-Kg',
        'skins'       => [
            [
                'id'          => 'gallery_awp_grand_prix',
                'weapon'      => 'AWP',
                'skin_name'   => 'Grand Prix',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 78.00,
                'min_float'   => 0.00,
                'max_float'   => 0.80,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'gallery_ak47_inheritance',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Inheritance',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 65.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'gallery_m4a4_龍王',
                'weapon'      => 'M4A4',
                'skin_name'   => 'Dragon King',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 28.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'gallery_deagle_kumicho',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Kumicho Dragon',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 7.50,
                'min_float'   => 0.00,
                'max_float'   => 0.70,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'gallery_usp_torque',
                'weapon'      => 'USP-S',
                'skin_name'   => 'Torque',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.60,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'gallery_nomad_night_stripe',
                'weapon'      => 'Nomad Knife',
                'skin_name'   => '★ Night Stripe',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 230.00,
                'min_float'   => 0.00,
                'max_float'   => 0.80,
                'can_stattrak'=> 1,
            ],
        ],
    ],

    // ── 10. Fever Case ──────────────────────────────────────────────────────
    [
        'id'          => 'fever_case',
        'name'        => 'Fever Case',
        'description' => 'Red-hot weapon designs that burn with intensity and neon fever aesthetics.',
        'price'       => 8.50,
        'key_price'   => 2.49,
        'image'       => $steamBase . 'i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj35VTqVBP4io_frncVtqv7MPE8JaHHCj_Dl-wk4-NtFirikURy4jiGwo2udHqVaAEjDZp3EflK7EeSMnMs4w',
        'skins'       => [
            [
                'id'          => 'fever_ak47_vulcan',
                'weapon'      => 'AK-47',
                'skin_name'   => 'Vulcan',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 88.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fever_awp_fever_dream',
                'weapon'      => 'AWP',
                'skin_name'   => 'Fever Dream',
                'rarity'      => 'covert',
                'rarity_name' => 'Covert',
                'rarity_color'=> '#eb4b4b',
                'rarity_tier' => 6,
                'base_price'  => 72.00,
                'min_float'   => 0.00,
                'max_float'   => 0.80,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fever_deagle_blaze',
                'weapon'      => 'Desert Eagle',
                'skin_name'   => 'Blaze',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 95.00,
                'min_float'   => 0.00,
                'max_float'   => 0.08,
                'can_stattrak'=> 0,
            ],
            [
                'id'          => 'fever_m4a4_neo_noir',
                'weapon'      => 'M4A4',
                'skin_name'   => 'Neo-Noir',
                'rarity'      => 'classified',
                'rarity_name' => 'Classified',
                'rarity_color'=> '#d32ce6',
                'rarity_tier' => 5,
                'base_price'  => 20.00,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fever_sg553_cyrex',
                'weapon'      => 'SG 553',
                'skin_name'   => 'Cyrex',
                'rarity'      => 'restricted',
                'rarity_name' => 'Restricted',
                'rarity_color'=> '#8847ff',
                'rarity_tier' => 4,
                'base_price'  => 6.00,
                'min_float'   => 0.00,
                'max_float'   => 0.60,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fever_aug_chameleon',
                'weapon'      => 'AUG',
                'skin_name'   => 'Chameleon',
                'rarity'      => 'milspec',
                'rarity_name' => 'Mil-Spec',
                'rarity_color'=> '#4b69ff',
                'rarity_tier' => 3,
                'base_price'  => 1.40,
                'min_float'   => 0.00,
                'max_float'   => 1.00,
                'can_stattrak'=> 1,
            ],
            [
                'id'          => 'fever_bowie_lore',
                'weapon'      => 'Bowie Knife',
                'skin_name'   => '★ Lore',
                'rarity'      => 'rare',
                'rarity_name' => 'Rare Special Item',
                'rarity_color'=> '#e4ae39',
                'rarity_tier' => 7,
                'base_price'  => 300.00,
                'min_float'   => 0.00,
                'max_float'   => 0.45,
                'can_stattrak'=> 1,
            ],
        ],
    ],
];

// ── 3. Insert cases & skins ────────────────────────────────────────────────
$caseStmt = $pdo->prepare(
    "INSERT INTO cases (id, name, description, price, key_price, image, is_active)
     VALUES (:id, :name, :description, :price, :key_price, :image, 1)
     ON DUPLICATE KEY UPDATE
         name        = VALUES(name),
         description = VALUES(description),
         price       = VALUES(price),
         key_price   = VALUES(key_price),
         image       = VALUES(image),
         is_active   = 1"
);

$skinStmt = $pdo->prepare(
    "INSERT INTO skins
        (id, case_id, name, weapon, skin_name, rarity, rarity_name,
         rarity_color, rarity_tier, image, base_price,
         min_float, max_float, can_be_stattrak)
     VALUES
        (:id, :case_id, :name, :weapon, :skin_name, :rarity, :rarity_name,
         :rarity_color, :rarity_tier, :image, :base_price,
         :min_float, :max_float, :can_be_stattrak)
     ON DUPLICATE KEY UPDATE
         case_id       = VALUES(case_id),
         name          = VALUES(name),
         weapon        = VALUES(weapon),
         skin_name     = VALUES(skin_name),
         rarity        = VALUES(rarity),
         rarity_name   = VALUES(rarity_name),
         rarity_color  = VALUES(rarity_color),
         rarity_tier   = VALUES(rarity_tier),
         image         = VALUES(image),
         base_price    = VALUES(base_price),
         min_float     = VALUES(min_float),
         max_float     = VALUES(max_float),
         can_be_stattrak = VALUES(can_be_stattrak)"
);

$totalCases = 0;
$totalSkins = 0;

foreach ($cases as $case) {
    // Insert case
    $caseStmt->execute([
        ':id'          => $case['id'],
        ':name'        => $case['name'],
        ':description' => $case['description'],
        ':price'       => $case['price'],
        ':key_price'   => $case['key_price'],
        ':image'       => $case['image'],
    ]);
    $totalCases++;
    log_msg("  Case inserted/updated: {$case['name']}");

    // Insert skins
    foreach ($case['skins'] as $skin) {
        $fullName = $skin['weapon'] . ' | ' . $skin['skin_name'];

        $skinStmt->execute([
            ':id'              => $skin['id'],
            ':case_id'         => $case['id'],
            ':name'            => $fullName,
            ':weapon'          => $skin['weapon'],
            ':skin_name'       => $skin['skin_name'],
            ':rarity'          => $skin['rarity'],
            ':rarity_name'     => $skin['rarity_name'],
            ':rarity_color'    => $skin['rarity_color'],
            ':rarity_tier'     => $skin['rarity_tier'],
            ':image'           => $skinPlaceholder,
            ':base_price'      => $skin['base_price'],
            ':min_float'       => $skin['min_float'],
            ':max_float'       => $skin['max_float'],
            ':can_be_stattrak' => $skin['can_stattrak'],
        ]);
        $totalSkins++;
        log_msg("    Skin inserted/updated: {$fullName} ({$skin['rarity_name']})");
    }
}

// ── Done ───────────────────────────────────────────────────────────────────
log_msg('');
log_msg("✓ Seeding complete.");
log_msg("  Cases : {$totalCases}");
log_msg("  Skins : {$totalSkins}");
log_msg('');
