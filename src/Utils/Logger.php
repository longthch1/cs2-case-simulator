<?php
declare(strict_types=1);

namespace CS2\Utils;

use CS2\Config\Config;

final class Logger
{
    private static function rotate(string $path, int $maxBytes, int $backupCount): void
    {
        if (!is_file($path) || filesize($path) < $maxBytes) {
            return;
        }

        for ($i = $backupCount - 1; $i >= 1; $i--) {
            $old = $path . '.' . $i;
            $new = $path . '.' . ($i + 1);
            if (is_file($old)) {
                if ($i === $backupCount - 1) {
                    @unlink($new);
                }
                @rename($old, $new);
            }
        }

        @rename($path, $path . '.1');
    }

    private static function write(string $file, string $level, string $message): void
    {
        $dir = Config::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $path = rtrim($dir, '/\\') . '/' . $file;
        $maxBytes = 10 * 1024 * 1024;
        // Match the original Python logging configuration:
        // app.log keeps 5 rotated backups and audit.log keeps 10.
        $backupCount = $file === 'audit.log' ? 10 : 5;
        self::rotate($path, $maxBytes, $backupCount);

        $line = sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            PHP_EOL
        );

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        error_log(rtrim($line));
    }

    public static function info(string $message): void
    {
        self::write('app.log', 'INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('app.log', 'WARNING', $message);
    }

    public static function error(string $message): void
    {
        self::write('app.log', 'ERROR', $message);
    }

    public static function audit(string $message): void
    {
        self::write('audit.log', 'AUDIT', $message);
    }

    public static function security(string $event, array $context = []): void
    {
        $payload = [
            'event' => $event,
            'timestamp' => gmdate('c'),
            'src_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'context' => $context,
        ];

        self::write(
            'security.log',
            'SECURITY',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
