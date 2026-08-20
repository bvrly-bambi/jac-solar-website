<?php

declare(strict_types=1);

namespace JacSolar;

use RuntimeException;

/**
 * Loads the Free Quote V1 runtime configuration.
 *
 * The real configuration lives OUTSIDE public_html and is never committed.
 * Primary path:
 *   /home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php
 *
 * A repository-local fallback (api/../config.php) is supported only to make
 * staging bring-up easier. That filename is excluded by .gitignore.
 */
final class Config
{
    public const EXTERNAL_PATH =
        '/home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php';

    /** @var array<string,mixed>|null */
    private static ?array $values = null;

    /** Keys that must exist and be non-empty strings/ints. */
    private const REQUIRED_KEYS = [
        'environment',
        'timezone',
        'db_host',
        'db_port',
        'db_name',
        'db_username',
        'db_password',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'sender_email',
        'sender_name',
        'internal_recipient',
        'private_upload_dir',
        'privacy_notice_version',
        'max_upload_bytes',
    ];

    /**
     * Load and cache configuration.
     *
     * @throws RuntimeException when the config file is missing or malformed.
     *                          The message is internal-only and must never be
     *                          echoed to the browser.
     */
    public static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        $path = self::resolvePath();

        if ($path === null) {
            throw new RuntimeException('Configuration file not found.');
        }

        /** @psalm-suppress UnresolvableInclude */
        $loaded = require $path;

        if (!is_array($loaded)) {
            throw new RuntimeException('Configuration file did not return an array.');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $loaded)) {
                throw new RuntimeException('Missing configuration key: ' . $key);
            }
        }

        // Passwords may legitimately be empty only in a non-production sandbox;
        // in production they must be set.
        if (($loaded['environment'] ?? '') === 'production') {
            foreach (['db_password', 'smtp_password'] as $secretKey) {
                if ((string) $loaded[$secretKey] === '') {
                    throw new RuntimeException('Empty required secret: ' . $secretKey);
                }
            }
        }

        self::$values = $loaded;
    }

    /** Staging config — alongside production config, outside the public webroot. */
    private const STAGING_CONFIG_PATH =
        '/home/u500192602/domains/jacsolarcorp.com/jac_quote_staging_config.php';

    /** Verified production document root (exact). */
    private const PRODUCTION_DOCROOT =
        '/home/u500192602/domains/jacsolarcorp.com/public_html';

    /** Verified staging document root (exact). */
    private const STAGING_DOCROOT =
        '/home/u500192602/domains/jacsolarcorp.com/public_html/public_html/staging-app';

    private static function resolvePath(): ?string
    {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
            ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/')
            : '';

        if ($docRoot !== '') {
            if ($docRoot === self::STAGING_DOCROOT) {
                return is_readable(self::STAGING_CONFIG_PATH)
                    ? self::STAGING_CONFIG_PATH
                    : null;  // staging config missing → fail closed
            }

            if ($docRoot === self::PRODUCTION_DOCROOT) {
                return is_readable(self::EXTERNAL_PATH)
                    ? self::EXTERNAL_PATH
                    : null;
            }

            return null;  // unrecognized docroot → fail closed
        }

        // CLI / no DOCUMENT_ROOT — unchanged from baseline.
        if (is_readable(self::EXTERNAL_PATH)) {
            return self::EXTERNAL_PATH;
        }

        $fallback = dirname(__DIR__, 2) . '/config.php';
        if (is_readable($fallback)) {
            return $fallback;
        }

        return null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        return self::$values[$key] ?? $default;
    }

    public static function string(string $key): string
    {
        return (string) self::get($key, '');
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function isProduction(): bool
    {
        return self::string('environment') === 'production';
    }
}
