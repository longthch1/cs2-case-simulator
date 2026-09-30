<?php
declare(strict_types=1);

namespace CS2\Utils;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $detail, int $status): never
    {
        self::json(['detail' => $detail], $status);
    }
}
