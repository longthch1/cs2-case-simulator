<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$index = dirname(__DIR__) . '/static/index.html';
if (!is_file($index)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Frontend file not found.";
    exit;
}
readfile($index);
