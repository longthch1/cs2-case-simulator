<?php
declare(strict_types=1);

namespace CS2\Database;

use CS2\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        try {
            self::$pdo = new PDO(
                Config::databaseDsn(),
                Config::databaseUser(),
                Config::databasePassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return self::$pdo;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
