<?php

declare(strict_types=1);

namespace JacSolar;

use DateTimeImmutable;
use RuntimeException;

/**
 * Generates JACQ-YYYYMMDD-##### reference numbers.
 *
 * The sequence restarts at 00001 for each Philippine calendar date and is
 * allocated by a single atomic upsert against quote_reference_counters.
 * MySQL applies that statement under a row lock held for its duration, so
 * concurrent requests serialize on the day's counter row and can never
 * receive the same number.
 *
 * MAX(), COUNT() and PHP-side counters are deliberately not used.
 */
final class ReferenceGenerator
{
    public const PREFIX = 'JACQ';

    /**
     * Allocate the next reference number for the given Philippine date.
     *
     * MUST be called inside an open transaction so that the allocated number
     * is rolled back with the lead if the insert that follows fails.
     */
    public static function next(DateTimeImmutable $nowManila): string
    {
        $pdo = Database::connection();

        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Reference allocation requires an open transaction.');
        }

        $datePh = $nowManila->format('Y-m-d');

        // Single-statement allocation.
        //
        // The upsert creates the day's counter row on first use and increments
        // it thereafter. LAST_INSERT_ID(expr) publishes the resulting value on
        // this connection, so it is read back without a second lookup.
        //
        // Because allocation is one statement, InnoDB acquires its locks in a
        // single step. An earlier three-statement version (INSERT IGNORE,
        // SELECT ... FOR UPDATE, UPDATE) deadlocked under concurrency: the
        // insert-intention lock taken by INSERT IGNORE conflicted with the row
        // lock another transaction already held, and InnoDB aborted one side.
        $upsert = $pdo->prepare(
            'INSERT INTO quote_reference_counters (date_ph, last_sequence)
                  VALUES (:date_ph, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE
                  last_sequence = LAST_INSERT_ID(last_sequence + 1)'
        );
        $upsert->execute([':date_ph' => $datePh]);

        $next = (int) $pdo->lastInsertId();

        if ($next < 1) {
            throw new RuntimeException('Reference counter did not return a sequence value.');
        }

        return self::format($nowManila, $next);
    }

    /** JACQ-YYYYMMDD-##### */
    public static function format(DateTimeImmutable $dateManila, int $sequence): string
    {
        return sprintf(
            '%s-%s-%05d',
            self::PREFIX,
            $dateManila->format('Ymd'),
            $sequence
        );
    }
}
