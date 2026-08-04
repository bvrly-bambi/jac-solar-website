<?php

declare(strict_types=1);

namespace JacSolar;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Single shared PDO connection.
 *
 * Exceptions are enabled internally. Callers catch PDOException, log a
 * sanitized message, and return a generic server_error response. Raw SQL
 * and driver messages never reach the browser.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::string('db_host'),
            Config::int('db_port', 3306),
            Config::string('db_name')
        );

        try {
            $pdo = new PDO(
                $dsn,
                Config::string('db_username'),
                Config::string('db_password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            // Do not include the DSN or credentials in any log line.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        // Keep MySQL session time in UTC; Asia/Manila values are computed in PHP.
        $pdo->exec("SET time_zone = '+00:00'");

        self::$pdo = $pdo;

        return self::$pdo;
    }

    public static function begin(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public static function rollBack(): void
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
