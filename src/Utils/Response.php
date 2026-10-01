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

    /**
     * Throw an HTTP exception instead of exiting immediately.
     * This is important inside MySQL transactions: Database::transaction()
     * must be able to catch the exception and roll back before the API
     * front controller serializes the error response.
     */
    public static function error(string $detail, int $status): never
    {
        throw new HttpException($detail, $status);
    }
}
