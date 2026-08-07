<?php

declare(strict_types=1);

namespace JacSolar;

use DateTimeImmutable;
use PDO;

/**
 * Idempotency and exact-duplicate detection.
 *
 * Idempotency: a replayed submission_token returns the original reference.
 * Exact duplicate: normalized email + normalized contact number + uploaded
 * file SHA-256 all matching within 15 minutes.
 */
final class DuplicateDetector
{
    public const WINDOW_MINUTES = 15;

    public static function fingerprint(string $email, string $contactNumber, string $fileHash): string
    {
        return hash('sha256', $email . '|' . $contactNumber . '|' . $fileHash);
    }

    public static function findBySubmissionToken(string $submissionToken): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT reference_number
               FROM quote_requests
              WHERE submission_token = :token
              LIMIT 1'
        );
        $stmt->execute([':token' => $submissionToken]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['reference_number'];
    }

    public static function findRecentDuplicate(
        string $fingerprint,
        DateTimeImmutable $nowManila
    ): ?string {
        $windowStart = $nowManila
            ->modify('-' . self::WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $stmt = Database::connection()->prepare(
            'SELECT reference_number
               FROM quote_requests
              WHERE duplicate_hash = :fingerprint
                AND submitted_at >= :window_start
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute([
            ':fingerprint'  => $fingerprint,
            ':window_start' => $windowStart,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['reference_number'];
    }

    /**
     * Serialize duplicate checks and insertion for the same fingerprint.
     * MySQL advisory-lock names are limited to 64 characters.
     */
    public static function acquireFingerprintLock(string $fingerprint, int $timeoutSeconds = 5): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT GET_LOCK(:lock_name, :timeout_seconds)'
        );
        $stmt->bindValue(':lock_name', self::lockName($fingerprint));
        $stmt->bindValue(':timeout_seconds', $timeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public static function releaseFingerprintLock(string $fingerprint): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT RELEASE_LOCK(:lock_name)'
            );
            $stmt->execute([':lock_name' => self::lockName($fingerprint)]);
        } catch (\Throwable $e) {
            Response::logInternal('duplicate-lock', 'Failed to release duplicate lock.');
        }
    }

    private static function lockName(string $fingerprint): string
    {
        return 'jacq_dup_' . substr($fingerprint, 0, 50);
    }
}
