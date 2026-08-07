<?php

declare(strict_types=1);

namespace JacSolar;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Per-IP rate limiting backed by quote_rate_limits.
 *
 * Approved V1 limit: 5 attempts per IP address per rolling hour.
 * A short MySQL advisory lock serializes check-and-record for the same IP,
 * preventing concurrent requests from slipping past the fifth-attempt limit.
 */
final class RateLimiter
{
    public const MAX_ATTEMPTS   = 5;
    public const WINDOW_SECONDS = 3600;

    /**
     * Atomically check and, when allowed, record one attempt.
     *
     * @return array{allowed:bool, retry_after:int}
     */
    public static function consume(string $ipAddress, DateTimeImmutable $nowManila): array
    {
        $pdo = Database::connection();
        $lockName = self::lockName($ipAddress);

        if (!self::acquireLock($pdo, $lockName, 3)) {
            throw new RuntimeException('Rate-limit lock could not be acquired.');
        }

        try {
            $result = self::checkUnlocked($pdo, $ipAddress, $nowManila);

            if ($result['allowed']) {
                self::recordUnlocked($pdo, $ipAddress, $nowManila);
            }

            return $result;
        } finally {
            self::releaseLock($pdo, $lockName);
        }
    }

    /**
     * Opportunistic cleanup of rows older than 24 hours.
     * Runs on roughly 1 in 50 requests to keep the table small.
     */
    public static function pruneOccasionally(DateTimeImmutable $nowManila): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        $cutoff = $nowManila->modify('-24 hours')->format('Y-m-d H:i:s');

        $stmt = Database::connection()->prepare(
            'DELETE FROM quote_rate_limits WHERE attempted_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
    }

    /**
     * @return array{allowed:bool, retry_after:int}
     */
    private static function checkUnlocked(
        PDO $pdo,
        string $ipAddress,
        DateTimeImmutable $nowManila
    ): array {
        $windowStart = $nowManila->modify('-' . self::WINDOW_SECONDS . ' seconds');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS attempts, MIN(attempted_at) AS oldest
               FROM quote_rate_limits
              WHERE ip_address = :ip
                AND attempted_at >= :window_start'
        );
        $stmt->execute([
            ':ip'           => $ipAddress,
            ':window_start' => $windowStart->format('Y-m-d H:i:s'),
        ]);

        $row      = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $attempts = (int) ($row['attempts'] ?? 0);

        if ($attempts < self::MAX_ATTEMPTS) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $retryAfter = self::WINDOW_SECONDS;
        $oldest     = $row['oldest'] ?? null;

        if (is_string($oldest) && $oldest !== '') {
            $oldestTime = strtotime($oldest . ' ' . $nowManila->getTimezone()->getName());
            if ($oldestTime !== false) {
                $elapsed    = $nowManila->getTimestamp() - $oldestTime;
                $retryAfter = max(60, self::WINDOW_SECONDS - $elapsed);
            }
        }

        return ['allowed' => false, 'retry_after' => $retryAfter];
    }

    private static function recordUnlocked(
        PDO $pdo,
        string $ipAddress,
        DateTimeImmutable $nowManila
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO quote_rate_limits (ip_address, attempted_at)
             VALUES (:ip, :attempted_at)'
        );
        $stmt->execute([
            ':ip'           => $ipAddress,
            ':attempted_at' => $nowManila->format('Y-m-d H:i:s'),
        ]);
    }

    private static function lockName(string $ipAddress): string
    {
        return 'jacq_rate_' . substr(hash('sha256', $ipAddress), 0, 48);
    }

    private static function acquireLock(PDO $pdo, string $lockName, int $timeout): bool
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $stmt->bindValue(':lock_name', $lockName);
        $stmt->bindValue(':timeout_seconds', $timeout, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function releaseLock(PDO $pdo, string $lockName): void
    {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute([':lock_name' => $lockName]);
        } catch (\Throwable $e) {
            Response::logInternal('rate-limit-lock', 'Failed to release rate-limit lock.');
        }
    }
}
