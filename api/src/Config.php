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

    private static function resolvePath(): ?string
    {
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
