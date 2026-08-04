<?php

declare(strict_types=1);

namespace JacSolar;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Outbound email via authenticated SMTP (PHPMailer).
 *
 * The customer's address is never used as the sender; it appears only as
 * Reply-To on the internal notification.
 *
 * All customer-supplied values are HTML-escaped before being placed in the
 * message body. Raw content is preserved only in the database.
 */
final class EmailService
{
    /**
     * @param array<string,mixed> $lead
     *
     * @return array{success:bool, error:?string}
     */
    public static function sendInternalNotification(array $lead, string $billPath): array
    {
        try {
            $mailer = self::makeMailer();

            $mailer->addAddress(Config::string('internal_recipient'));
            $mailer->addReplyTo((string) $lead['email'], (string) $lead['full_name']);

            $mailer->Subject = sprintf(
                'New JAC Solar Quote Request — %s — %s',
                (string) $lead['reference_number'],
                (string) $lead['full_name']
            );

            if ($billPath !== '' && is_file($billPath)) {
                $mailer->addAttachment(
                    $billPath,
                    self::attachmentName($lead)
                );
            }

            $mailer->isHTML(true);
            $mailer->Body    = self::internalHtml($lead);
            $mailer->AltBody = self::internalText($lead);

            $mailer->send();

            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => self::safeError($e->getMessage())];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Unexpected mailer failure.'];
        }
    }

    /**
     * @param array<string,mixed> $lead
     *
     * @return array{success:bool, error:?string}
     */
    public static function sendCustomerAcknowledgment(array $lead): array
    {
        try {
            $mailer = self::makeMailer();

            $mailer->addAddress((string) $lead['email'], (string) $lead['full_name']);

            $mailer->Subject = sprintf(
                'We Received Your JAC Solar Quote Request — %s',
                (string) $lead['reference_number']
            );

            $mailer->isHTML(true);
            $mailer->Body    = self::customerHtml($lead);
            $mailer->AltBody = self::customerText($lead);

            // The bill is never attached to the customer copy.
            $mailer->send();

            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => self::safeError($e->getMessage())];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Unexpected mailer failure.'];
        }
    }

    // ── Mailer construction ─────────────────────────────────────────────────

    private static function makeMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host       = Config::string('smtp_host');
        $mailer->Port       = Config::int('smtp_port', 465);
        $mailer->SMTPAuth   = true;
        $mailer->Username   = Config::string('smtp_username');
        $mailer->Password   = Config::string('smtp_password');
        $mailer->CharSet    = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding   = PHPMailer::ENCODING_BASE64;
        $mailer->Timeout    = 20;
        $mailer->SMTPDebug  = SMTP::DEBUG_OFF;

        $encryption = strtolower(Config::string('smtp_encryption'));
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure = false;
            $mailer->SMTPAutoTLS = false;
        }

        $mailer->setFrom(
            Config::string('sender_email'),
            Config::string('sender_name')
        );

        return $mailer;
    }

    /** @param array<string,mixed> $lead */
    private static function attachmentName(array $lead): string
    {
        $extension = pathinfo((string) $lead['stored_filename'], PATHINFO_EXTENSION);
        $extension = preg_replace('/[^a-z0-9]/i', '', (string) $extension) ?: 'dat';

        return sprintf('%s-electricity-bill.%s', (string) $lead['reference_number'], $extension);
    }

    // ── Bodies ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $lead */
    private static function internalHtml(array $lead): string
    {
        $rows = '';
        foreach (self::internalRows($lead) as $label => $value) {
            $rows .= sprintf(
                '<tr><th align="left" style="padding:6px 12px 6px 0;vertical-align:top;'
                . 'font-family:Arial,sans-serif;font-size:13px;color:#555;white-space:nowrap;">%s</th>'
                . '<td style="padding:6px 0;font-family:Arial,sans-serif;font-size:14px;color:#111;">%s</td></tr>',
                self::e($label),
                self::e($value)
            );
        }

        return '<html><body style="margin:0;padding:24px;background:#f6f7f9;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">'
            . '<h2 style="margin:0 0 4px;font-family:Arial,sans-serif;font-size:18px;color:#111;">'
            . 'New Free Quote Request</h2>'
            . '<p style="margin:0 0 20px;font-family:Arial,sans-serif;font-size:14px;color:#555;">Reference: <strong>'
            . self::e((string) $lead['reference_number']) . '</strong></p>'
            . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
            . $rows
            . '</table>'
            . '<p style="margin:20px 0 0;font-family:Arial,sans-serif;font-size:12px;color:#777;">'
            . 'The customer\'s electricity bill is attached to this email.</p>'
            . '</div></body></html>';
    }

    /** @param array<string,mixed> $lead */
    private static function internalText(array $lead): string
    {
        $lines = ['New Free Quote Request', 'Reference: ' . (string) $lead['reference_number'], ''];

        foreach (self::internalRows($lead) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = 'The customer\'s electricity bill is attached to this email.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $lead
     *
     * @return array<string,string>
     */
    private static function internalRows(array $lead): array
    {
        return [
            'Submitted (PH time)'   => (string) $lead['submitted_at'],
            'Full name'             => (string) $lead['full_name'],
            'Contact number'        => (string) $lead['contact_number'],
            'Email'                 => (string) $lead['email'],
            'Project location'      => (string) $lead['project_location'],
            'Electricity provider'  => (string) $lead['electricity_provider'],
            'Property type'         => (string) $lead['property_type'],
            'Monthly bill range'    => (string) $lead['bill_range'],
            'Message'               => self::orDash($lead['message'] ?? null),
            'Specific requirements' => self::orDash($lead['specific_requirements'] ?? null),
            'Bill filename'         => (string) $lead['original_filename'],
            'Bill size'             => self::humanBytes((int) $lead['file_size']),
            'Bill SHA-256'          => (string) $lead['file_hash_sha256'],
            'Processing consent'    => $lead['processing_consent'] ? 'Yes' : 'No',
            'Marketing consent'     => $lead['marketing_consent'] ? 'Yes' : 'No',
            'Privacy notice'        => (string) $lead['privacy_notice_version'],
            'Lead status'           => QuoteRepository::INITIAL_STATUS,
        ];
    }

    /** @param array<string,mixed> $lead */
    private static function customerHtml(array $lead): string
    {
        $summary = '';
        foreach (self::customerRows($lead) as $label => $value) {
            $summary .= sprintf(
                '<tr><th align="left" style="padding:6px 12px 6px 0;vertical-align:top;'
                . 'font-family:Arial,sans-serif;font-size:13px;color:#555;white-space:nowrap;">%s</th>'
                . '<td style="padding:6px 0;font-family:Arial,sans-serif;font-size:14px;color:#111;">%s</td></tr>',
                self::e($label),
                self::e($value)
            );
        }

        return '<html><body style="margin:0;padding:24px;background:#f6f7f9;">'
            . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:28px;">'
            . '<h2 style="margin:0 0 12px;font-family:Arial,sans-serif;font-size:20px;color:#111;">'
            . 'Thank you for your request</h2>'
            . '<p style="margin:0 0 16px;font-family:Arial,sans-serif;font-size:15px;color:#333;line-height:1.6;">Hi '
            . self::e((string) $lead['full_name'])
            . ', we have received your free quotation request. Your reference number is:</p>'
            . '<p style="margin:0 0 24px;font-family:Arial,sans-serif;font-size:20px;font-weight:bold;color:#0f7b3f;">'
            . self::e((string) $lead['reference_number']) . '</p>'
            . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px;">'
            . $summary
            . '</table>'
            . '<p style="margin:0 0 12px;font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6;">'
            . 'Our team will review the electricity bill you uploaded as part of preparing your quotation.</p>'
            . '<p style="margin:0 0 12px;font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6;">'
            . 'JAC Solar normally responds within one to two business days.</p>'
            . '<p style="margin:0 0 12px;font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6;">'
            . 'For questions, email <a href="mailto:' . self::e(Config::string('internal_recipient'))
            . '" style="color:#0f7b3f;">' . self::e(Config::string('internal_recipient')) . '</a>.</p>'
            . '<p style="margin:24px 0 0;font-family:Arial,sans-serif;font-size:12px;color:#777;line-height:1.6;">'
            . 'This message confirms receipt of your request. It is not an approval, '
            . 'a quotation, or a system recommendation.</p>'
            . '<p style="margin:16px 0 0;font-family:Arial,sans-serif;font-size:13px;color:#555;">'
            . 'JAC Solar Corporation</p>'
            . '</div></body></html>';
    }

    /** @param array<string,mixed> $lead */
    private static function customerText(array $lead): string
    {
        $lines = [
            'Thank you for your request',
            '',
            'Hi ' . (string) $lead['full_name'] . ',',
            '',
            'We have received your free quotation request.',
            'Your reference number is: ' . (string) $lead['reference_number'],
            '',
        ];

        foreach (self::customerRows($lead) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = 'Our team will review the electricity bill you uploaded as part of preparing your quotation.';
        $lines[] = 'JAC Solar normally responds within one to two business days.';
        $lines[] = 'For questions, email ' . Config::string('internal_recipient') . '.';
        $lines[] = '';
        $lines[] = 'This message confirms receipt of your request. It is not an approval, '
                 . 'a quotation, or a system recommendation.';
        $lines[] = '';
        $lines[] = 'JAC Solar Corporation';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $lead
     *
     * @return array<string,string>
     */
    private static function customerRows(array $lead): array
    {
        return [
            'Name'                 => (string) $lead['full_name'],
            'Contact number'       => (string) $lead['contact_number'],
            'Email'                => (string) $lead['email'],
            'Project location'     => (string) $lead['project_location'],
            'Electricity provider' => (string) $lead['electricity_provider'],
            'Property type'        => (string) $lead['property_type'],
            'Monthly bill range'   => (string) $lead['bill_range'],
            'Bill uploaded'        => (string) $lead['original_filename'],
            'Submitted (PH time)'  => (string) $lead['submitted_at'],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Render an optional field, falling back to an em dash when empty. */
    private static function orDash(mixed $value): string
    {
        if (!is_string($value)) {
            return '—';
        }

        $value = trim($value);

        return $value === '' ? '—' : $value;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.0f KB', $bytes / 1024);
        }

        return $bytes . ' B';
    }

    /**
     * Trim a mailer error for database logging. This value is stored in
     * quote_email_events.error_summary and is never returned to the browser.
     */
    private static function safeError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        // Defensive: strip anything that looks like a credential fragment.
        // Empty needles are skipped; str_ireplace does not accept them safely.
        $secrets = array_values(array_filter([
            Config::string('smtp_password'),
            Config::string('db_password'),
        ], static fn (string $secret): bool => $secret !== ''));

        if ($secrets !== []) {
            $message = str_ireplace($secrets, '[redacted]', $message);
        }

        return mb_substr(trim($message), 0, 500, 'UTF-8');
    }
}
