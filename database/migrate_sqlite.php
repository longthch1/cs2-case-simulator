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
