<?php

declare(strict_types=1);

/**
 * POST /api/submit-quote.php  (multipart/form-data)
 *
 * Free Quote V1 submission handler.
 *
 * Processing order (approved):
 *   1. Method, HTTPS, origin, rate limit, session, CSRF, submission token
 *   2. Validate and normalize fields
 *   3. Validate the temporary upload and compute its SHA-256
 *   4. Idempotency replay and exact-duplicate checks
 *   5. Move the bill into private storage under a random name
 *   6. Begin transaction
 *   7. Allocate the atomic daily reference number
 *   8. Insert the lead with status New
 *   9. Commit
 *  10. Attempt emails (after the lead is safely saved)
 *  11. Log each email attempt
 *  12. Return the reference number
 *
 * Returns JSON only.
 */

use JacSolar\Config;
use JacSolar\Database;
use JacSolar\DuplicateDetector;
use JacSolar\EmailService;
use JacSolar\QuoteRepository;
use JacSolar\RateLimiter;
use JacSolar\ReferenceGenerator;
use JacSolar\Response;
use JacSolar\Security;
use JacSolar\UploadService;
use JacSolar\Validator;

require_once __DIR__ . '/bootstrap.php';

// ── 1. Transport, method and origin ─────────────────────────────────────────

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    Response::methodNotAllowed();
}

if (!Security::isHttps()) {
    Response::send([
        'status'  => Response::STATUS_SERVER_ERROR,
        'message' => 'A secure connection is required.',
        'errors'  => [],
    ], 403);
}

if (!Security::isSameSite()) {
    Response::csrfError();
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_contains($contentType, 'multipart/form-data')) {
    Response::send([
        'status'  => Response::STATUS_VALIDATION_ERROR,
        'message' => 'Unsupported content type.',
        'errors'  => [],
    ], 415);
}

// A body larger than post_max_size arrives with empty $_POST and $_FILES.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
    Response::uploadError([
        UploadService::FIELD => 'That file is too large. The maximum size is 10 MB.',
    ]);
}

$clientIp  = Security::clientIp();
$nowManila = new DateTimeImmutable('now', new DateTimeZone(Config::string('timezone') ?: 'Asia/Manila'));

// ── 1b. Session, CSRF and submission token ──────────────────────────────────

Security::startSession();

$csrfToken       = is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : null;
$submissionToken = is_string($_POST['submission_token'] ?? null) ? (string) $_POST['submission_token'] : null;

if (!Security::verifyCsrfToken($csrfToken)) {
    Response::csrfError();
}

if (!Security::isWellFormedToken($submissionToken)) {
    Response::csrfError();
}

/**
 * A completed request clears the session copy of the submission token.
 * On browser refresh/replay, recover the original reference from MySQL
 * instead of returning a misleading CSRF error.
 */
if (!Security::verifySubmissionToken($submissionToken)) {
    try {
        $replayReference = DuplicateDetector::findBySubmissionToken($submissionToken);
        if ($replayReference !== null) {
            Response::alreadyReceived($replayReference);
        }
    } catch (Throwable $e) {
        Response::logInternal('idempotency-replay', get_class($e));
        Response::serverError();
    }

    Response::csrfError();
}

/** @var string $submissionToken */

// ── 1c. Atomic rate-limit check and record ──────────────────────────────────

try {
    $limit = RateLimiter::consume($clientIp, $nowManila);

    if (!$limit['allowed']) {
        Response::rateLimited($limit['retry_after']);
    }

    RateLimiter::pruneOccasionally($nowManila);
} catch (Throwable $e) {
    Response::logInternal('rate-limit', get_class($e));
    Response::serverError();
}

// ── 2. Field validation and normalization ───────────────────────────────────

$validator = new Validator();

if (!$validator->validate($_POST)) {
    Response::validationError($validator->errors());
}

$clean = $validator->clean();

// ── 3. Upload validation and hashing ────────────────────────────────────────

$uploadResult = UploadService::inspect($_FILES[UploadService::FIELD] ?? null);

if (!($uploadResult['ok'] ?? false)) {
    Response::uploadError([
        UploadService::FIELD => (string) ($uploadResult['error'] ?? 'The file could not be processed.'),
    ]);
}

/** @var array{tmp_path:string,original_filename:string,mime:string,extension:string,size:int,sha256:string} $fileMeta */
$fileMeta = $uploadResult['meta'];

// ── 4. Idempotency and exact-duplicate checks ───────────────────────────────

$fingerprint = DuplicateDetector::fingerprint(
    (string) $clean['email'],
    (string) $clean['contact_number'],
    $fileMeta['sha256']
);

try {
    if (!DuplicateDetector::acquireFingerprintLock($fingerprint)) {
        throw new RuntimeException('Duplicate lock could not be acquired.');
    }
} catch (Throwable $e) {
    Response::logInternal('duplicate-lock', get_class($e));
    Response::serverError();
}

try {
    $replayReference = DuplicateDetector::findBySubmissionToken($submissionToken);
    if ($replayReference !== null) {
        // Replayed token: no new record, no new file, no new email.
        DuplicateDetector::releaseFingerprintLock($fingerprint);
        Response::alreadyReceived($replayReference);
    }

    $duplicateReference = DuplicateDetector::findRecentDuplicate($fingerprint, $nowManila);
    if ($duplicateReference !== null) {
        // Exact duplicate inside the window: the temporary upload is discarded
        // by PHP at shutdown, so no second copy is retained.
        DuplicateDetector::releaseFingerprintLock($fingerprint);
        Response::alreadyReceived($duplicateReference);
    }
} catch (Throwable $e) {
    DuplicateDetector::releaseFingerprintLock($fingerprint);
    Response::logInternal('duplicate-check', get_class($e));
    Response::serverError();
}

// ── 5. Move the bill into private storage ───────────────────────────────────

try {
    $storedFilename = UploadService::store($fileMeta);
} catch (Throwable $e) {
    DuplicateDetector::releaseFingerprintLock($fingerprint);
    Response::logInternal('upload-store', get_class($e));
    Response::uploadError([
        UploadService::FIELD => 'We could not save your file. Please try again shortly.',
    ]);
}

// ── 6–9. Transaction: reference allocation, insert, commit ──────────────────

$referenceNumber = '';
$quoteRequestId  = 0;
$submittedAt     = $nowManila->format('Y-m-d H:i:s');

try {
    Database::begin();

    $referenceNumber = ReferenceGenerator::next($nowManila);

    $quoteRequestId = QuoteRepository::insert([
        'reference_number'       => $referenceNumber,
        'full_name'              => $clean['full_name'],
        'contact_number'         => $clean['contact_number'],
        'email'                  => $clean['email'],
        'project_location'       => $clean['project_location'],
        'electricity_provider'   => $clean['electricity_provider'],
        'property_type'          => $clean['property_type'],
        'bill_range'             => $clean['bill_range'],
        'message'                => $clean['message'],
        'specific_requirements'  => $clean['specific_requirements'],
        'original_filename'      => $fileMeta['original_filename'],
        'stored_filename'        => $storedFilename,
        'file_mime_type'         => $fileMeta['mime'],
        'file_size'              => $fileMeta['size'],
        'file_hash_sha256'       => $fileMeta['sha256'],
        'processing_consent'     => $clean['processing_consent'],
        'processing_consent_at'  => $submittedAt,
        'marketing_consent'      => $clean['marketing_consent'],
        'marketing_consent_at'   => $clean['marketing_consent'] ? $submittedAt : null,
        'privacy_notice_version' => Config::string('privacy_notice_version'),
        'submission_token'       => $submissionToken,
        'duplicate_hash'         => $fingerprint,
        'duplicate_of_reference' => null,
        'ip_address'             => $clientIp,
        'submitted_at'           => $submittedAt,
    ]);

    Database::commit();
} catch (Throwable $e) {
    try {
        Database::rollBack();
    } catch (Throwable $rollbackError) {
        Response::logInternal('rollback', get_class($rollbackError));
    }

    // The lead was not saved, so the stored file must not be kept.
    UploadService::remove($storedFilename);
    DuplicateDetector::releaseFingerprintLock($fingerprint);

    Response::logInternal('insert', get_class($e));
    Response::serverError();
}

// ── The lead is now committed. Nothing below may delete it or report failure.

DuplicateDetector::releaseFingerprintLock($fingerprint);

// Retire the submission token so a refresh cannot reuse it for a new lead.
unset($_SESSION[Security::SUBMIT_SESSION_KEY]);

$lead = [
    'reference_number'       => $referenceNumber,
    'full_name'              => $clean['full_name'],
    'contact_number'         => $clean['contact_number'],
    'email'                  => $clean['email'],
    'project_location'       => $clean['project_location'],
    'electricity_provider'   => $clean['electricity_provider'],
    'property_type'          => $clean['property_type'],
    'bill_range'             => $clean['bill_range'],
    'message'                => $clean['message'],
    'specific_requirements'  => $clean['specific_requirements'],
    'original_filename'      => $fileMeta['original_filename'],
    'stored_filename'        => $storedFilename,
    'file_size'              => $fileMeta['size'],
    'file_hash_sha256'       => $fileMeta['sha256'],
    'processing_consent'     => $clean['processing_consent'],
    'marketing_consent'      => $clean['marketing_consent'],
    'privacy_notice_version' => Config::string('privacy_notice_version'),
    'submitted_at'           => $submittedAt,
];

// ── 10–11. Emails, then per-attempt logging ─────────────────────────────────

$internalOk = false;
$customerOk = false;

try {
    $attemptAt = new DateTimeImmutable('now', new DateTimeZone(Config::string('timezone') ?: 'Asia/Manila'));

    $internal = EmailService::sendInternalNotification(
        $lead,
        UploadService::pathFor($storedFilename)
    );
    $internalOk = $internal['success'];

    QuoteRepository::logEmailEvent(
        $quoteRequestId,
        QuoteRepository::EMAIL_INTERNAL,
        Config::string('internal_recipient'),
        $attemptAt,
        $internal['success'],
        $internal['error']
    );
} catch (Throwable $e) {
    Response::logInternal('email-internal', get_class($e));
}

try {
    $attemptAt = new DateTimeImmutable('now', new DateTimeZone(Config::string('timezone') ?: 'Asia/Manila'));

    $customer = EmailService::sendCustomerAcknowledgment($lead);
    $customerOk = $customer['success'];

    QuoteRepository::logEmailEvent(
        $quoteRequestId,
        QuoteRepository::EMAIL_CUSTOMER,
        (string) $clean['email'],
        $attemptAt,
        $customer['success'],
        $customer['error']
    );
} catch (Throwable $e) {
    Response::logInternal('email-customer', get_class($e));
}

// ── 12. Result ──────────────────────────────────────────────────────────────

$notice = null;

if (!$internalOk || !$customerOk) {
    $notice = 'Your request is recorded and your reference number is confirmed. '
            . 'The email confirmation may be delayed.';
}

Response::success($referenceNumber, $notice);
