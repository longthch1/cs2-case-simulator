<?php
declare(strict_types=1);

namespace CS2\Services;

use CS2\Database\Database;

final class RateLimiter
{
    public static function allow(string $key, int $maxRequests, int $windowSeconds = 60): bool
    {
        return Database::transaction(function ($pdo) use ($key, $maxRequests, $windowSeconds): bool {
            $stmt = $pdo->prepare("SELECT window_start, request_count FROM rate_limits WHERE rate_key = ? FOR UPDATE");
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if (!$row) {
                $insert = $pdo->prepare("INSERT INTO rate_limits (rate_key, window_start, request_count) VALUES (?, ?, 1)");
                $insert->execute([$key, $now->format('Y-m-d H:i:s')]);
                return true;
            }

            $windowStart = new \DateTimeImmutable($row['window_start'], new \DateTimeZone('UTC'));
            if (($now->getTimestamp() - $windowStart->getTimestamp()) >= $windowSeconds) {
                $update = $pdo->prepare("UPDATE rate_limits SET window_start = ?, request_count = 1 WHERE rate_key = ?");
                $update->execute([$now->format('Y-m-d H:i:s'), $key]);
                return true;
            }

            if ((int)$row['request_count'] >= $maxRequests) {
                return false;
            }

            $update = $pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE rate_key = ?");
            $update->execute([$key]);
            return true;
        });
    }
}
