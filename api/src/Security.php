<?php

declare(strict_types=1);

namespace JacSolar;

/**
 * Session, CSRF, transport and origin controls.
 */
final class Security
{
    public const SESSION_NAME       = 'JACQSESS';
    public const CSRF_SESSION_KEY   = 'jacq_csrf_token';
    public const SUBMIT_SESSION_KEY = 'jacq_submission_token';

    /**
     * Start a hardened session.
     * Cookie flags: Secure, HttpOnly, SameSite=Lax.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');

        session_start();
    }

    /** 32 random bytes rendered as 64 hex characters (matches CHAR(64) columns). */
    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Issue (or reuse) the CSRF token for this session.
     */
    public static function issueCsrfToken(): string
    {
        if (
            empty($_SESSION[self::CSRF_SESSION_KEY])
            || !is_string($_SESSION[self::CSRF_SESSION_KEY])
        ) {
            $_SESSION[self::CSRF_SESSION_KEY] = self::randomToken();
        }

        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * Issue a fresh submission/idempotency token for a new form render.
     */
    public static function issueSubmissionToken(): string
    {
        $token = self::randomToken();
        $_SESSION[self::SUBMIT_SESSION_KEY] = $token;

        return $token;
    }

    /** Timing-safe CSRF comparison against the session value. */
    public static function verifyCsrfToken(?string $supplied): bool
    {
        $expected = $_SESSION[self::CSRF_SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($supplied)) {
            return false;
        }

        return hash_equals($expected, $supplied);
    }

    /** Timing-safe check that the submission token was the one we issued. */
    public static function verifySubmissionToken(?string $supplied): bool
    {
        $expected = $_SESSION[self::SUBMIT_SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($supplied)) {
            return false;
        }

        return hash_equals($expected, $supplied);
    }

    /** Submission tokens are 64 lowercase hex characters. */
    public static function isWellFormedToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * Require HTTPS.
     *
     * Hostinger terminates TLS at its edge, so X-Forwarded-Proto is accepted
     * here. This header is used ONLY for the transport check, never for the
     * client IP used in rate limiting.
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwardedProto) && strtolower(trim($forwardedProto)) === 'https') {
            return true;
        }

        return false;
    }

    /**
     * Same-site check.
     *
     * Accepts a request whose Origin (preferred) or Referer host matches the
     * host serving the endpoint. A request with neither header is rejected,
     * because browsers always send at least one on a cross-document POST.
     */
    public static function isSameSite(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return false;
        }

        $host = self::stripPort($host);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (is_string($origin) && $origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);

            return is_string($originHost)
                && self::stripPort(strtolower($originHost)) === $host;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (is_string($referer) && $referer !== '') {
            $refererHost = parse_url($referer, PHP_URL_HOST);

            return is_string($refererHost)
                && self::stripPort(strtolower($refererHost)) === $host;
        }

        return false;
    }

    private static function stripPort(string $host): string
    {
        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * Client IP for rate limiting.
     *
     * REMOTE_ADDR only. X-Forwarded-For is deliberately ignored because no
     * verified trusted-proxy allowlist is configured; trusting it would let a
     * caller trivially bypass the per-IP limit.
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        return $ip;
    }
}
