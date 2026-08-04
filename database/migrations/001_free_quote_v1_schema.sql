-- ============================================================================
-- JAC Solar Free Quote V1 — Database Schema
-- Migration: 001_free_quote_v1_schema.sql
-- Date: 2026-07-30
-- Charset: utf8mb4_unicode_ci
--
-- INSTRUCTIONS:
--   Execute on the target database ONCE via phpMyAdmin or CLI:
--     mysql -u u500192602_quotes_app -p u500192602_jac_quotes < 001_free_quote_v1_schema.sql
--
-- ROLLBACK:
--   DROP TABLE IF EXISTS quote_rate_limits;
--   DROP TABLE IF EXISTS quote_email_events;
--   DROP TABLE IF EXISTS quote_reference_counters;
--   DROP TABLE IF EXISTS quote_requests;
-- ============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ─── A. quote_requests ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS quote_requests (
    id                      BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,

    -- Reference & identity
    reference_number        VARCHAR(25)         NOT NULL,
    full_name               VARCHAR(100)        NOT NULL,
    contact_number          VARCHAR(30)         NOT NULL,
    email                   VARCHAR(320)        NOT NULL,

    -- Project details
    project_location        VARCHAR(255)        NOT NULL,
    electricity_provider    VARCHAR(150)        NOT NULL,
    property_type           VARCHAR(50)         NOT NULL,
    bill_range              VARCHAR(50)         NOT NULL,

    -- Optional fields
    message                 TEXT                NULL DEFAULT NULL,
    specific_requirements   TEXT                NULL DEFAULT NULL,

    -- Uploaded file metadata
    original_filename       VARCHAR(255)        NOT NULL,
    stored_filename         VARCHAR(255)        NOT NULL,
    file_mime_type          VARCHAR(100)        NOT NULL,
    file_size               INT UNSIGNED        NOT NULL,
    file_hash_sha256        CHAR(64)            NOT NULL,

    -- Consent
    processing_consent      TINYINT(1)          NOT NULL DEFAULT 0,
    processing_consent_at   DATETIME            NULL DEFAULT NULL,
    marketing_consent       TINYINT(1)          NOT NULL DEFAULT 0,
    marketing_consent_at    DATETIME            NULL DEFAULT NULL,
    privacy_notice_version  VARCHAR(30)         NOT NULL,

    -- Lead lifecycle
    lead_status             VARCHAR(30)         NOT NULL DEFAULT 'New',

    -- Submission integrity
    submission_token        CHAR(64)            NOT NULL,
    duplicate_hash          CHAR(64)            NOT NULL,
    duplicate_of_reference  VARCHAR(25)         NULL DEFAULT NULL,

    -- Request metadata
    ip_address              VARCHAR(45)         NOT NULL,
    submitted_at            DATETIME            NOT NULL COMMENT 'Asia/Manila time',

    -- Record timestamps (UTC)
    created_at              DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Soft archive
    is_archived             TINYINT(1)          NOT NULL DEFAULT 0,
    archived_at             DATETIME            NULL DEFAULT NULL,

    PRIMARY KEY (id),

    -- Unique constraints
    UNIQUE KEY uq_reference_number  (reference_number),
    UNIQUE KEY uq_submission_token  (submission_token),

    -- Duplicate detection: same hash within a time window (checked in app logic)
    INDEX idx_duplicate_hash        (duplicate_hash, submitted_at),

    -- Lookup & filtering indexes
    INDEX idx_email                 (email),
    INDEX idx_contact_number        (contact_number),
    INDEX idx_lead_status           (lead_status),
    INDEX idx_submitted_at          (submitted_at),
    INDEX idx_archived              (is_archived, lead_status),
    INDEX idx_file_hash             (file_hash_sha256)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Free Quote V1 lead submissions';


-- ─── B. quote_reference_counters ────────────────────────────────────────────
-- Atomic daily counter for JACQ-YYYYMMDD-##### reference numbers.
-- Each row represents one Philippine-date day.
-- The application increments last_sequence atomically per insert.

CREATE TABLE IF NOT EXISTS quote_reference_counters (
    date_ph         DATE            NOT NULL COMMENT 'Philippine date (Asia/Manila)',
    last_sequence   INT UNSIGNED    NOT NULL DEFAULT 0,

    PRIMARY KEY (date_ph)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily atomic counter for JACQ-YYYYMMDD-##### reference numbers';


-- ─── C. quote_email_events ──────────────────────────────────────────────────
-- Separate log row per email attempt (internal notification vs customer ack).
-- Decoupled from lead lifecycle: email failure never deletes a lead.

CREATE TABLE IF NOT EXISTS quote_email_events (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    quote_request_id BIGINT UNSIGNED    NOT NULL,

    email_type      VARCHAR(30)         NOT NULL COMMENT 'internal_notification | customer_acknowledgment',
    recipient       VARCHAR(320)        NOT NULL,

    attempted_at    DATETIME            NOT NULL,
    is_success      TINYINT(1)          NOT NULL DEFAULT 0,
    error_summary   TEXT                NULL DEFAULT NULL,
    retry_count     TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    next_retry_at   DATETIME            NULL DEFAULT NULL,

    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_quote_request     (quote_request_id),
    INDEX idx_email_type        (email_type, is_success),
    INDEX idx_retry             (is_success, next_retry_at),

    CONSTRAINT fk_email_quote_request
        FOREIGN KEY (quote_request_id)
        REFERENCES quote_requests (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Email send attempts per quote request';


-- ─── D. quote_rate_limits ───────────────────────────────────────────────────
-- Tracks submission attempts per IP.
-- Application enforces: max 5 attempts per IP per rolling hour.

CREATE TABLE IF NOT EXISTS quote_rate_limits (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    ip_address      VARCHAR(45)         NOT NULL,
    attempted_at    DATETIME            NOT NULL,

    PRIMARY KEY (id),

    INDEX idx_ip_time (ip_address, attempted_at)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Rate limit tracking: 5 submissions per IP per hour';
