<?php
declare(strict_types=1);

namespace CS2\Utils;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function intQuery(string $key, int $default, int $min = 0, int $max = 10000): int
    {
        $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value === null) {
            return $default;
        }
        return max($min, min($max, $value));
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Response::error('Invalid JSON request body.', 400);
        }
        return $decoded;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
