<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use CS2\Database\Database;
use PDO;

$options = getopt('', ['sqlite:']);
$sqlitePath = $options['sqlite'] ?? __DIR__ . '/../data/cs2_simulator.db';

if (!is_file($sqlitePath)) {
    fwrite(STDERR, 'SQLite database not found: ' . $sqlitePath . PHP_EOL);
    exit(1);
}

$tables = [
    'users' => ['id','username','email','password_hash','legacy_password_hash','legacy_salt','role','balance','daily_streak','last_daily_claim','avatar_url','created_at','last_login'],
    'cases' => ['id','name','description','price','key_price','image','is_active','created_at'],
    'skins' => ['id','case_id','name','weapon','skin_name','rarity','rarity_name','rarity_color','rarity_tier','image','base_price','min_float','max_float','can_be_stattrak'],
    'inventory' => ['id','user_id','skin_id','float_value','wear_name','is_stattrak','value','is_sold','acquired_at'],
    'open_history' => ['id','user_id','case_id','inventory_id','cost','payout','server_seed','server_seed_hash','client_seed','nonce','roll_number','created_at'],
    'tradeup_history' => ['id','user_id','input_inventory_ids','output_inventory_id','input_tier','output_tier','output_float','created_at'],
    'transactions' => ['id','user_id','type','amount','balance_after','description','created_at'],
    'audit_logs' => ['id','user_id','action','ip_address','user_agent','details','created_at'],
    'hourly_codes' => ['id','code','reward_amount','created_at','expires_at','is_active'],
    'code_redemptions' => ['id','user_id','code','amount','redeemed_at']
];

try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $mysql = Database::connection();
    $mysql->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table => $columns) {
        if ($table === 'users') {
            $rows = $sqlite->query('SELECT id,username,email,password_hash,salt,role,balance,daily_streak,last_daily_claim,avatar_url,created_at,last_login FROM users');
        } else {
            $rows = $sqlite->query('SELECT ' . implode(',', $columns) . ' FROM ' . $table);
        }

        $marks = implode(',', array_fill(0, count($columns), '?'));
        $columnSql = implode(',', $columns);
        $first = $columns[0];
        $sql = 'INSERT INTO ' . $table . ' (' . $columnSql . ') VALUES (' . $marks . ') ON DUPLICATE KEY UPDATE ' . $first . '=' . $first;
        $insert = $mysql->prepare($sql);

        $count = 0;
        while ($row = $rows->fetch()) {
            $values = [];
            foreach ($columns as $column) {
                if ($table === 'users' && $column === 'legacy_password_hash') {
                    $values[] = $row['password_hash'] ?? null;
                } elseif ($table === 'users' && $column === 'legacy_salt') {
                    $values[] = $row['salt'] ?? null;
                } else {
                    $values[] = $row[$column] ?? null;
                }
            }
            $insert->execute($values);
            $count++;
        }

        echo sprintf('Migrated %-18s %d rows%s', $table, $count, PHP_EOL);
    }

    $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
    echo 'Migration complete. Legacy passwords can be upgraded after a successful login.' . PHP_EOL;
} catch (Throwable $e) {
    if (isset($mysql)) {
        try { $mysql->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
