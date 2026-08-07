<?php
/**
 * JAC Solar Free Quote V1 — Configuration Template
 *
 * INSTRUCTIONS:
 * 1. Copy this file to the EXTERNAL config path (outside public_html):
 *    /home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php
 *
 * 2. Fill in the real values for your environment (staging or production).
 *
 * 3. NEVER commit the real configuration file to GitHub.
 *    The .gitignore excludes jac_quote_config.php and config.php.
 *
 * 4. The PHP application loads this config at runtime via:
 *    require_once '/home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php';
 */

return [

    // ─── Environment ───
    // 'staging' or 'production'
    'environment' => 'staging',

    // ─── Application ───
    'timezone' => 'Asia/Manila',

    // ─── Database ───
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'u500192602_jac_quotes',
    'db_username' => 'u500192602_quotes_app',
    'db_password' => '',  // ← fill in on server

    // ─── SMTP (Hostinger) ───
    'smtp_host'       => 'smtp.hostinger.com',
    'smtp_port'       => 465,
    'smtp_username'   => 'quotes@jacsolarcorp.com',
    'smtp_password'   => '',  // ← fill in on server
    'smtp_encryption' => 'ssl',

    // ─── Email identities ───
    'sender_email'      => 'quotes@jacsolarcorp.com',
    'sender_name'       => 'JAC Solar Quotes',
    'internal_recipient'=> 'chris@jacsolarcorp.com',

    // ─── File uploads ───
    // Absolute path OUTSIDE public_html — must exist and be writable by PHP
    'private_upload_dir' => '/home/u500192602/domains/jacsolarcorp.com/jac_quote_private_uploads',

    // Maximum upload size in bytes (10 MB)
    'max_upload_bytes' => 10485760,

    // ─── Privacy ───
    'privacy_notice_version' => '2026-07-30-v1',

];
