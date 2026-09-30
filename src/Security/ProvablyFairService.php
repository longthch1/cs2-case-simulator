<?php
declare(strict_types=1);

namespace CS2\Security;

final class ProvablyFairService
{
    public static function generateServerSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashServerSeed(string $serverSeed): string
    {
        return hash('sha256', $serverSeed);
    }

    public static function calculateRoll(string $serverSeed, string $clientSeed, int $nonce, int $subIndex = 0): float
    {
        $message = sprintf('%s:%d:%d', $clientSeed, $nonce, $subIndex);
        $digest = hash_hmac('sha256', $message, $serverSeed);
        $value = hexdec(substr($digest, 0, 8));
        return $value / 4294967296;
    }

    public static function calculateAttributes(string $serverSeed, string $clientSeed, int $nonce, int $subIndex = 0): array
    {
        $message = sprintf('%s:%d:%d:attributes', $clientSeed, $nonce, $subIndex);
        $digest = hash_hmac('sha256', $message, $serverSeed);

        $stValue = hexdec(substr($digest, 8, 8)) / 4294967296;
        $floatRaw = hexdec(substr($digest, 16, 8)) / 4294967296;

        return [$stValue < 0.10, $floatRaw];
    }

    public static function rarityFromRoll(float $roll): int
    {
        if ($roll < 0.79920) return 1;
        if ($roll < 0.95900) return 2;
        if ($roll < 0.99100) return 3;
        if ($roll < 0.99740) return 4;
        return 5;
    }

    public static function wear(float $floatValue): array
    {
        if ($floatValue < 0.07) return ['Factory New', 1.6];
        if ($floatValue < 0.15) return ['Minimal Wear', 1.2];
        if ($floatValue < 0.38) return ['Field-Tested', 1.0];
        if ($floatValue < 0.45) return ['Well-Worn', 0.85];
        return ['Battle-Scarred', 0.70];
    }
}
