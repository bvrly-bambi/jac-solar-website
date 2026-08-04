<?php

declare(strict_types=1);

namespace JacSolar;

/**
 * JSON response helper.
 *
 * Every public endpoint returns JSON only. No HTML, no stack traces,
 * no SQL text, no SMTP transcripts, no filesystem paths.
 */
final class Response
{
    public const STATUS_SUCCESS          = 'success';
    public const STATUS_ALREADY_RECEIVED = 'already_received';
    public const STATUS_VALIDATION_ERROR = 'validation_error';
    public const STATUS_CSRF_ERROR       = 'csrf_error';
    public const STATUS_RATE_LIMITED     = 'rate_limited';
    public const STATUS_UPLOAD_ERROR     = 'upload_error';
    public const STATUS_SERVER_ERROR     = 'server_error';

    private static bool $sent = false;

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function send(array $payload, int $httpStatus = 200): never
    {
        if (!self::$sent) {
            self::$sent = true;
            self::sendHeaders();

            if (!headers_sent()) {
                http_response_code($httpStatus);
            }

            echo json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        exit;
    }

    public static function success(
        string $referenceNumber,
        ?string $notice = null
    ): never {
        self::send([
            'status'           => self::STATUS_SUCCESS,
            'reference_number' => $referenceNumber,
            'message'          => 'Your quote request has been received.',
            'notice'           => $notice,
        ], 200);
    }

    public static function alreadyReceived(string $referenceNumber): never
    {
        self::send([
            'status'           => self::STATUS_ALREADY_RECEIVED,
            'reference_number' => $referenceNumber,
            'message'          => 'We already received this request. '
                                . 'Your reference number is shown below.',
            'notice'           => null,
        ], 200);
    }

    /**
     * @param array<string,string> $errors
     */
    public static function validationError(array $errors): never
    {
        self::send([
            'status'  => self::STATUS_VALIDATION_ERROR,
            'message' => 'Please review the highlighted fields and try again.',
            'errors'  => $errors,
        ], 422);
    }

    /**
     * @param array<string,string> $errors
     */
    public static function uploadError(array $errors): never
    {
        self::send([
            'status'  => self::STATUS_UPLOAD_ERROR,
            'message' => 'There was a problem with the uploaded electricity bill.',
            'errors'  => $errors,
        ], 422);
    }

    public static function csrfError(): never
    {
        self::send([
            'status'  => self::STATUS_CSRF_ERROR,
            'message' => 'Your session expired. Please refresh the page and try again.',
            'errors'  => [],
        ], 403);
    }

    public static function rateLimited(int $retryAfterSeconds): never
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfterSeconds);
        }

        self::send([
            'status'              => self::STATUS_RATE_LIMITED,
            'message'             => 'Too many submissions from this connection. '
                                   . 'Please try again later.',
            'retry_after_seconds' => $retryAfterSeconds,
            'errors'              => [],
        ], 429);
    }

    public static function serverError(): never
    {
        self::send([
            'status'  => self::STATUS_SERVER_ERROR,
            'message' => 'We could not process your request right now. '
                       . 'Please try again shortly or contact us directly.',
            'errors'  => [],
        ], 500);
    }

    public static function methodNotAllowed(): never
    {
        if (!headers_sent()) {
            header('Allow: POST');
        }

        self::send([
            'status'  => self::STATUS_SERVER_ERROR,
            'message' => 'Unsupported request method.',
            'errors'  => [],
        ], 405);
    }

    /**
     * Log an internal diagnostic without leaking it to the browser.
     * Secrets are never passed here by callers.
     */
    public static function logInternal(string $context, string $detail): void
    {
        error_log(sprintf('[free-quote-v1] %s: %s', $context, $detail));
    }
}
