<?php
declare(strict_types=1);

namespace CS2\Config;

final class Config
{
    private static ?array $env = null;

    public static function loadEnv(string $file): void
    {
        if (self::$env !== null) {
            return;
        }

        self::$env = [];
        if (!is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, ""'");
            self::$env[$key] = $value;
        }
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? self::$env[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    public static function isProduction(): bool
    {
        return self::env('APP_ENV', 'production') === 'production';
    }

    public static function baseDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function logDir(): string
    {
        return self::env('LOG_DIR', self::baseDir() . '/logs') ?? (self::baseDir() . '/logs');
    }

    public static function storageDir(): string
    {
        return self::env('STORAGE_DIR', self::baseDir() . '/storage') ?? (self::baseDir() . '/storage');
    }

    public static function databaseDsn(): string
    {
        $host = self::env('DB_HOST', '127.0.0.1');
        $port = self::env('DB_PORT', '3306');
        $name = self::env('DB_NAME', 'cs2_case_simulator');
        return "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    }

    public static function databaseUser(): string
    {
        return self::env('DB_USER', 'cs2_app') ?? 'cs2_app';
    }

    public static function databasePassword(): string
    {
        return self::env('DB_PASSWORD', '') ?? '';
    }

    public static function adminUsername(): string
    {
        return self::env('ADMIN_USERNAME', 'admin') ?? 'admin';
    }

    public static function adminEmail(): string
    {
        return self::env('ADMIN_EMAIL', 'admin@localhost') ?? 'admin@localhost';
    }

    public static function adminPassword(): string
    {
        return self::env('ADMIN_PASSWORD', '') ?? '';
    }

    public static function startingBalance(): float
    {
        return (float) (self::env('USER_STARTING_BALANCE', '0.00') ?? '0.00');
    }

    public static function adminStartingBalance(): float
    {
        return (float) (self::env('ADMIN_STARTING_BALANCE', '10000.00') ?? '10000.00');
    }
}
