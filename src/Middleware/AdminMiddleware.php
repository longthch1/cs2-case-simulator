<?php
declare(strict_types=1);

namespace CS2\Middleware;

use CS2\Utils\Response;

final class AdminMiddleware
{
    public static function user(array $user): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            Response::error('Administrator privileges required for this action', 403);
        }
        return $user;
    }
}
