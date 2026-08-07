<?php

declare(strict_types=1);

/**
 * LOCAL-ONLY integration test.
 *
 * Exercises ReferenceGenerator, QuoteRepository, DuplicateDetector and
 * RateLimiter against a throwaway database. It never touches staging or
 * production and contains no real credentials: the connection details are
 * read from environment variables supplied by the developer running it.
 *
 * Required environment variables:
 *   JACQ_TEST_DB_HOST, JACQ_TEST_DB_PORT, JACQ_TEST_DB_NAME,
 *   JACQ_TEST_DB_USER, JACQ_TEST_DB_PASS
 *
 * Run:  JACQ_TEST_DB_... php tests/integration_local.php
 * Exit: 0 when every assertion passes, 1 otherwise.
 *
 * This file must never be uploaded to Hostinger.
 */

require_once __DIR__ . '/../api/src/Config.php';
require_once __DIR__ . '/../api/src/Response.php';
require_once __DIR__ . '/../api/src/Database.php';
require_once __DIR__ . '/../api/src/Validator.php';
require_once __DIR__ . '/../api/src/RateLimiter.php';
require_once __DIR__ . '/../api/src/DuplicateDetector.php';
require_once __DIR__ . '/../api/src/ReferenceGenerator.php';
require_once __DIR__ . '/../api/src/QuoteRepository.php';

use JacSolar\Database;
use JacSolar\DuplicateDetector;
use JacSolar\QuoteRepository;
use JacSolar\RateLimiter;
use JacSolar\ReferenceGenerator;

$required = [
    'JACQ_TEST_DB_HOST',
    'JACQ_TEST_DB_PORT',
    'JACQ_TEST_DB_NAME',
    'JACQ_TEST_DB_USER',
    'JACQ_TEST_DB_PASS',
];

foreach ($required as $variable) {
    if (getenv($variable) === false) {
        fwrite(STDERR, "Missing environment variable: {$variable}\n");
        exit(1);
    }
}

// Build a throwaway PDO using the same options as Database::connection().
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('JACQ_TEST_DB_HOST'),
        (int) getenv('JACQ_TEST_DB_PORT'),
        getenv('JACQ_TEST_DB_NAME')
    ),
    (string) getenv('JACQ_TEST_DB_USER'),
    (string) getenv('JACQ_TEST_DB_PASS'),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// Inject the test connection into the Database facade.
$reflection = new ReflectionClass(Database::class);
$property   = $reflection->getProperty('pdo');
$property->setAccessible(true);
$property->setValue(null, $pdo);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

$tz    = new DateTimeZone('Asia/Manila');
$now   = new DateTimeImmutable('2026-07-30 09:00:00', $tz);
$later = new DateTimeImmutable('2026-07-31 09:00:00', $tz);

// Clean slate.
$pdo->exec('DELETE FROM quote_email_events');
$pdo->exec('DELETE FROM quote_requests');
$pdo->exec('DELETE FROM quote_reference_counters');
$pdo->exec('DELETE FROM quote_rate_limits');

echo "\n── Reference number allocation ──\n";

Database::begin();
$first = ReferenceGenerator::next($now);
Database::commit();
check('first reference is 00001', $first === 'JACQ-20260730-00001');

Database::begin();
$second = ReferenceGenerator::next($now);
Database::commit();
check('second reference is 00002', $second === 'JACQ-20260730-00002');

Database::begin();
$nextDay = ReferenceGenerator::next($later);
Database::commit();
check('new PH date resets to 00001', $nextDay === 'JACQ-20260731-00001');

Database::begin();
$backSameDay = ReferenceGenerator::next($now);
Database::commit();
check('original date continues at 00003', $backSameDay === 'JACQ-20260730-00003');

try {
    ReferenceGenerator::next($now);
    check('refuses allocation outside a transaction', false);
} catch (RuntimeException $e) {
    check('refuses allocation outside a transaction', true);
}

echo "\n── Lead insert ──\n";

function makeLead(string $reference, string $token, string $fingerprint, string $submittedAt): array
{
    return [
        'reference_number'       => $reference,
        'full_name'              => 'Juan Dela Cruz',
        'contact_number'         => '+639171234567',
        'email'                  => 'juan@example.com',
        'project_location'       => 'Davao City',
        'electricity_provider'   => 'Davao Light',
        'property_type'          => 'Residential',
        'bill_range'             => '₱8,000–₱12,000',
        'message'                => null,
        'specific_requirements'  => null,
        'original_filename'      => 'bill.pdf',
        'stored_filename'        => '20260730_abc123.pdf',
        'file_mime_type'         => 'application/pdf',
        'file_size'              => 204800,
        'file_hash_sha256'       => str_repeat('a', 64),
        'processing_consent'     => true,
        'processing_consent_at'  => $submittedAt,
        'marketing_consent'      => false,
        'marketing_consent_at'   => null,
        'privacy_notice_version' => '2026-07-30-v1',
        'submission_token'       => $token,
        'duplicate_hash'         => $fingerprint,
        'duplicate_of_reference' => null,
        'ip_address'             => '203.0.113.10',
        'submitted_at'           => $submittedAt,
    ];
}

$fingerprint = DuplicateDetector::fingerprint(
    'juan@example.com',
    '+639171234567',
    str_repeat('a', 64)
);
$tokenA      = str_repeat('1', 64);
$submittedAt = $now->format('Y-m-d H:i:s');

Database::begin();
$leadId = QuoteRepository::insert(makeLead($first, $tokenA, $fingerprint, $submittedAt));
Database::commit();

check('lead inserted', $leadId > 0);

$row = $pdo->query('SELECT * FROM quote_requests WHERE id = ' . $leadId)->fetch();
check('status defaults to New', $row['lead_status'] === 'New');
check('reference stored', $row['reference_number'] === $first);
check('peso/en-dash bill range round-trips', $row['bill_range'] === '₱8,000–₱12,000');
check('processing consent stored as 1', (int) $row['processing_consent'] === 1);
check('marketing consent stored as 0', (int) $row['marketing_consent'] === 0);
check('marketing timestamp null', $row['marketing_consent_at'] === null);
check('archived defaults to 0', (int) $row['is_archived'] === 0);
check('sha256 stored at full length', strlen((string) $row['file_hash_sha256']) === 64);

echo "\n── Idempotency and duplicates ──\n";

check(
    'replayed token finds original reference',
    DuplicateDetector::findBySubmissionToken($tokenA) === $first
);
check(
    'unknown token finds nothing',
    DuplicateDetector::findBySubmissionToken(str_repeat('9', 64)) === null
);

check(
    'duplicate found inside 15-minute window',
    DuplicateDetector::findRecentDuplicate($fingerprint, $now->modify('+10 minutes')) === $first
);
check(
    'duplicate ignored outside 15-minute window',
    DuplicateDetector::findRecentDuplicate($fingerprint, $now->modify('+16 minutes')) === null
);

$duplicateTokenRejected = false;
try {
    Database::begin();
    QuoteRepository::insert(makeLead($second, $tokenA, $fingerprint, $submittedAt));
    Database::commit();
} catch (PDOException $e) {
    Database::rollBack();
    $duplicateTokenRejected = ((string) $e->getCode() === '23000');
}
check('unique submission_token enforced by database', $duplicateTokenRejected);

$duplicateReferenceRejected = false;
try {
    Database::begin();
    QuoteRepository::insert(makeLead($first, str_repeat('2', 64), $fingerprint, $submittedAt));
    Database::commit();
} catch (PDOException $e) {
    Database::rollBack();
    $duplicateReferenceRejected = ((string) $e->getCode() === '23000');
}
check('unique reference_number enforced by database', $duplicateReferenceRejected);

echo "\n── Email event logging ──\n";

QuoteRepository::logEmailEvent(
    $leadId,
    QuoteRepository::EMAIL_INTERNAL,
    'chris@jacsolarcorp.com',
    $now,
    true
);
QuoteRepository::logEmailEvent(
    $leadId,
    QuoteRepository::EMAIL_CUSTOMER,
    'juan@example.com',
    $now,
    false,
    'SMTP connect failed'
);

$events = $pdo->query(
    'SELECT email_type, is_success, next_retry_at FROM quote_email_events
      WHERE quote_request_id = ' . $leadId . ' ORDER BY id'
)->fetchAll();

check('two email events recorded', count($events) === 2);
check('internal logged as success', (int) $events[0]['is_success'] === 1);
check('internal has no retry time', $events[0]['next_retry_at'] === null);
check('customer logged as failure', (int) $events[1]['is_success'] === 0);
check('failed email schedules a retry', $events[1]['next_retry_at'] !== null);

$restrictWorks = false;
try {
    $pdo->exec('DELETE FROM quote_requests WHERE id = ' . $leadId);
} catch (PDOException $e) {
    $restrictWorks = ((string) $e->getCode() === '23000');
}
check('ON DELETE RESTRICT protects leads with email events', $restrictWorks);

echo "\n── Rate limiting ──\n";

$ip     = '198.51.100.5';
$rlNow  = new DateTimeImmutable('2026-07-30 12:00:00', $tz);

for ($i = 1; $i <= RateLimiter::MAX_ATTEMPTS; $i++) {
    $state = RateLimiter::consume($ip, $rlNow);
    check("attempt {$i} of " . RateLimiter::MAX_ATTEMPTS . ' allowed', $state['allowed'] === true);
}

$blocked = RateLimiter::consume($ip, $rlNow);
check('sixth attempt blocked', $blocked['allowed'] === false);
check('retry-after is positive', $blocked['retry_after'] > 0);

$afterWindow = RateLimiter::consume($ip, $rlNow->modify('+61 minutes'));
check('allowed again after the window', $afterWindow['allowed'] === true);

$otherIp = RateLimiter::consume('198.51.100.99', $rlNow);
check('limit is per IP', $otherIp['allowed'] === true);

echo "\n────────────────────────────────\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
