<?php
declare(strict_types=1);

namespace CS2\Middleware;

use CS2\Auth\AuthService;
use CS2\Utils\Response;

final class AuthMiddleware
{
    public static function user(): array
    {
        $user = AuthService::currentUser();
        if (!$user) {
            Response::error('Authentication required', 401);
        }
        return $user;
    }
}
