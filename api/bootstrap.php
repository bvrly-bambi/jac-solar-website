<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the Free Quote V1 endpoints.
 *
 * Loads the Composer autoloader (PHPMailer + JacSolar\ PSR-4), then the
 * external configuration, then fixes the application timezone.
 *
 * Display errors are always off: endpoints return JSON only.
 */

use JacSolar\Config;
use JacSolar\Response;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(500);
    error_log('[free-quote-v1] Composer autoloader missing. Run composer install.');
    echo json_encode([
        'status'  => 'server_error',
        'message' => 'We could not process your request right now. Please try again shortly.',
        'errors'  => [],
    ]);
    exit;
}

require_once $autoload;

try {
    Config::load();
} catch (Throwable $e) {
    Response::logInternal('bootstrap', 'Configuration could not be loaded.');
    Response::serverError();
}

date_default_timezone_set(Config::string('timezone') ?: 'Asia/Manila');

/**
 * Convert unhandled errors into a generic JSON response so that no warning,
 * notice, path, or stack trace is ever rendered to the client.
 */
set_exception_handler(static function (Throwable $e): void {
    Response::logInternal('unhandled', get_class($e));
    Response::serverError();
});
