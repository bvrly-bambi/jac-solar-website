<?php

declare(strict_types=1);

/**
 * GET /api/csrf-token.php
 *
 * Starts a hardened session and returns a CSRF token plus a fresh
 * submission/idempotency token for one form render.
 *
 * Returns JSON only. No configuration values, server paths, or session
 * identifiers are exposed. Never cached.
 */

use JacSolar\Response;
use JacSolar\Security;

require_once __DIR__ . '/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

if ($method !== 'GET' && $method !== 'HEAD') {
    Response::send([
        'status'  => Response::STATUS_SERVER_ERROR,
        'message' => 'Unsupported request method.',
        'errors'  => [],
    ], 405);
}

if (!Security::isHttps()) {
    Response::send([
        'status'  => Response::STATUS_SERVER_ERROR,
        'message' => 'A secure connection is required.',
        'errors'  => [],
    ], 403);
}

Security::startSession();

$csrfToken       = Security::issueCsrfToken();
$submissionToken = Security::issueSubmissionToken();

Response::send([
    'status'           => Response::STATUS_SUCCESS,
    'csrf_token'       => $csrfToken,
    'submission_token' => $submissionToken,
], 200);
