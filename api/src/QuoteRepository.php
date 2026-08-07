<?php

declare(strict_types=1);

namespace JacSolar;

use DateTimeImmutable;

/**
 * Persistence for quote_requests and quote_email_events.
 *
 * Every statement is a PDO prepared statement with bound parameters.
 * Nothing in this class deletes or archives quote data.
 */
final class QuoteRepository
{
    public const INITIAL_STATUS = 'New';

    public const EMAIL_INTERNAL = 'internal_notification';
    public const EMAIL_CUSTOMER = 'customer_acknowledgment';

    /**
     * Insert an accepted lead.
     *
     * @param array<string,mixed> $data
     *
     * @return int The new quote_requests.id
     */
    public static function insert(array $data): int
    {
        $sql = 'INSERT INTO quote_requests (
                    reference_number,
                    full_name,
                    contact_number,
                    email,
                    project_location,
                    electricity_provider,
                    property_type,
                    bill_range,
                    message,
                    specific_requirements,
                    original_filename,
                    stored_filename,
                    file_mime_type,
                    file_size,
                    file_hash_sha256,
                    processing_consent,
                    processing_consent_at,
                    marketing_consent,
                    marketing_consent_at,
                    privacy_notice_version,
                    lead_status,
                    submission_token,
                    duplicate_hash,
                    duplicate_of_reference,
                    ip_address,
                    submitted_at
                ) VALUES (
                    :reference_number,
                    :full_name,
                    :contact_number,
                    :email,
                    :project_location,
                    :electricity_provider,
                    :property_type,
                    :bill_range,
                    :message,
                    :specific_requirements,
                    :original_filename,
                    :stored_filename,
                    :file_mime_type,
                    :file_size,
                    :file_hash_sha256,
                    :processing_consent,
                    :processing_consent_at,
                    :marketing_consent,
                    :marketing_consent_at,
                    :privacy_notice_version,
                    :lead_status,
                    :submission_token,
                    :duplicate_hash,
                    :duplicate_of_reference,
                    :ip_address,
                    :submitted_at
                )';

        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':reference_number'       => $data['reference_number'],
            ':full_name'              => $data['full_name'],
            ':contact_number'         => $data['contact_number'],
            ':email'                  => $data['email'],
            ':project_location'       => $data['project_location'],
            ':electricity_provider'   => $data['electricity_provider'],
            ':property_type'          => $data['property_type'],
            ':bill_range'             => $data['bill_range'],
            ':message'                => $data['message'],
            ':specific_requirements'  => $data['specific_requirements'],
            ':original_filename'      => $data['original_filename'],
            ':stored_filename'        => $data['stored_filename'],
            ':file_mime_type'         => $data['file_mime_type'],
            ':file_size'              => $data['file_size'],
            ':file_hash_sha256'       => $data['file_hash_sha256'],
            ':processing_consent'     => $data['processing_consent'] ? 1 : 0,
            ':processing_consent_at'  => $data['processing_consent_at'],
            ':marketing_consent'      => $data['marketing_consent'] ? 1 : 0,
            ':marketing_consent_at'   => $data['marketing_consent_at'],
            ':privacy_notice_version' => $data['privacy_notice_version'],
            ':lead_status'            => self::INITIAL_STATUS,
            ':submission_token'       => $data['submission_token'],
            ':duplicate_hash'         => $data['duplicate_hash'],
            ':duplicate_of_reference' => $data['duplicate_of_reference'],
            ':ip_address'             => $data['ip_address'],
            ':submitted_at'           => $data['submitted_at'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Record one email attempt. Never throws into the request path: a logging
     * failure must not affect a lead that is already committed.
     */
    public static function logEmailEvent(
        int $quoteRequestId,
        string $emailType,
        string $recipient,
        DateTimeImmutable $attemptedAtManila,
        bool $success,
        ?string $errorSummary = null,
        int $retryCount = 0
    ): void {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO quote_email_events (
                     quote_request_id,
                     email_type,
                     recipient,
                     attempted_at,
                     is_success,
                     error_summary,
                     retry_count,
                     next_retry_at
                 ) VALUES (
                     :quote_request_id,
                     :email_type,
                     :recipient,
                     :attempted_at,
                     :is_success,
                     :error_summary,
                     :retry_count,
                     :next_retry_at
                 )'
            );

            $nextRetryAt = $success
                ? null
                : $attemptedAtManila->modify('+15 minutes')->format('Y-m-d H:i:s');

            $stmt->execute([
                ':quote_request_id' => $quoteRequestId,
                ':email_type'       => $emailType,
                ':recipient'        => $recipient,
                ':attempted_at'     => $attemptedAtManila->format('Y-m-d H:i:s'),
                ':is_success'       => $success ? 1 : 0,
                ':error_summary'    => $errorSummary === null
                    ? null
                    : mb_substr($errorSummary, 0, 500, 'UTF-8'),
                ':retry_count'      => $retryCount,
                ':next_retry_at'    => $nextRetryAt,
            ]);
        } catch (\Throwable $e) {
            Response::logInternal('email-log', 'Failed to record email event.');
        }
    }
}
