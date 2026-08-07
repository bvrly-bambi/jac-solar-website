<?php

declare(strict_types=1);

namespace JacSolar;

/**
 * Server-side validation and normalization for the Free Quote form.
 *
 * All rules here are authoritative. Client-side validation is convenience only.
 */
final class Validator
{
    /** Approved V1 property types. */
    public const PROPERTY_TYPES = [
        'Residential',
        'Commercial / Industrial',
        'Agricultural',
        'School / Institution',
        'Government',
        'Other',
    ];

    /**
     * Approved V1 customer-facing monthly bill ranges.
     * Peso sign and en dash are significant; see normalizeChoice().
     */
    public const BILL_RANGES = [
        'Below ₱5,000',
        '₱5,000–₱8,000',
        '₱6,000–₱10,000',
        '₱8,000–₱12,000',
        '₱10,000–₱14,000',
        '₱14,000–₱18,000',
        '₱16,000–₱22,000',
        '₱18,000–₱24,000',
        '₱20,000–₱30,000',
        '₱30,000 and above',
    ];

    /** Honeypot field name. Must remain empty. */
    public const HONEYPOT_FIELD = 'website';

    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $clean = [];

    /**
     * @param array<string,mixed> $input Raw $_POST
     */
    public function validate(array $input): bool
    {
        $this->errors = [];
        $this->clean  = [];

        // Honeypot: silently invalid if filled by a bot.
        $honeypot = $this->scalar($input, self::HONEYPOT_FIELD);
        if ($honeypot !== '') {
            $this->errors['_'] = 'Submission rejected.';

            return false;
        }

        $this->validateFullName($input);
        $this->validateContactNumber($input);
        $this->validateEmail($input);
        $this->validateProjectLocation($input);
        $this->validateElectricityProvider($input);
        $this->validatePropertyType($input);
        $this->validateBillRange($input);
        $this->validateOptionalText($input, 'message', 2000);
        $this->validateOptionalText($input, 'specific_requirements', 2000);
        $this->validateConsent($input);

        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function clean(): array
    {
        return $this->clean;
    }

    // ── Individual field rules ──────────────────────────────────────────────

    /** @param array<string,mixed> $input */
    private function validateFullName(array $input): void
    {
        $value = $this->scalar($input, 'full_name');
        $value = $this->collapseWhitespace($value);
        $length = mb_strlen($value, 'UTF-8');

        if ($length < 2 || $length > 100) {
            $this->errors['full_name'] = 'Please enter your full name (2–100 characters).';

            return;
        }

        $this->clean['full_name'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateContactNumber(array $input): void
    {
        $raw = $this->scalar($input, 'contact_number');
        $normalized = self::normalizePhilippineNumber($raw);

        if ($normalized === null) {
            $this->errors['contact_number'] =
                'Please enter a valid Philippine contact number (e.g. 0917 123 4567 or +63 917 123 4567).';

            return;
        }

        $this->clean['contact_number'] = $normalized;
    }

    /** @param array<string,mixed> $input */
    private function validateEmail(array $input): void
    {
        $value = strtolower(trim($this->scalar($input, 'email')));

        if ($value === '' || mb_strlen($value, 'UTF-8') > 320) {
            $this->errors['email'] = 'Please enter your email address.';

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['email'] = 'Please enter a valid email address.';

            return;
        }

        $this->clean['email'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateProjectLocation(array $input): void
    {
        $value = $this->collapseWhitespace($this->scalar($input, 'project_location'));

        if ($value === '') {
            $this->errors['project_location'] = 'Please enter the project location.';

            return;
        }

        if (mb_strlen($value, 'UTF-8') > 255) {
            $this->errors['project_location'] = 'Project location is too long (maximum 255 characters).';

            return;
        }

        $this->clean['project_location'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateElectricityProvider(array $input): void
    {
        $value = $this->collapseWhitespace($this->scalar($input, 'electricity_provider'));

        if ($value === '') {
            $this->errors['electricity_provider'] = 'Please enter your electricity provider.';

            return;
        }

        if (mb_strlen($value, 'UTF-8') > 150) {
            $this->errors['electricity_provider'] = 'Electricity provider is too long (maximum 150 characters).';

            return;
        }

        $this->clean['electricity_provider'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validatePropertyType(array $input): void
    {
        $value = self::normalizeChoice($this->scalar($input, 'property_type'));

        if (!in_array($value, self::PROPERTY_TYPES, true)) {
            $this->errors['property_type'] = 'Please choose a property type from the list.';

            return;
        }

        $this->clean['property_type'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateBillRange(array $input): void
    {
        $value = self::normalizeChoice($this->scalar($input, 'bill_range'));

        if (!in_array($value, self::BILL_RANGES, true)) {
            $this->errors['bill_range'] = 'Please choose a monthly bill range from the list.';

            return;
        }

        $this->clean['bill_range'] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateOptionalText(array $input, string $field, int $max): void
    {
        $value = trim($this->scalar($input, $field));

        if ($value === '') {
            $this->clean[$field] = null;

            return;
        }

        if (mb_strlen($value, 'UTF-8') > $max) {
            $this->errors[$field] = sprintf('This field is too long (maximum %d characters).', $max);

            return;
        }

        $this->clean[$field] = $value;
    }

    /** @param array<string,mixed> $input */
    private function validateConsent(array $input): void
    {
        $processing = self::isTruthy($this->scalar($input, 'processing_consent'));

        if (!$processing) {
            $this->errors['processing_consent'] =
                'Please allow us to process your details so we can prepare your quotation.';
        }

        $this->clean['processing_consent'] = $processing;
        $this->clean['marketing_consent']  = self::isTruthy($this->scalar($input, 'marketing_consent'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Read a scalar field. Arrays are rejected outright so that a caller
     * cannot smuggle array values into fields expected to be strings.
     *
     * @param array<string,mixed> $input
     */
    private function scalar(array $input, string $key): string
    {
        if (!array_key_exists($key, $input)) {
            return '';
        }

        $value = $input[$key];

        if (is_array($value) || is_object($value)) {
            $this->errors[$key] = 'Invalid value submitted.';

            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function collapseWhitespace(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Normalize a select value before allowlist comparison.
     *
     * Trims, collapses whitespace, and folds the common dash variants
     * (hyphen-minus, en dash, em dash, minus sign) to the en dash used in the
     * approved bill-range labels. The result is still matched strictly against
     * the allowlist, so no unapproved value can pass.
     */
    public static function normalizeChoice(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return strtr($value, [
            '-'      => '–',
            '—'      => '–',
            "\u{2212}" => '–',
        ]);
    }

    private static function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Normalize a Philippine mobile or landline number to +63XXXXXXXXXX.
     *
     * Accepts local (0917…, 02…) and international (+63…, 63…) formats with
     * arbitrary spaces, dashes, dots, and parentheses.
     *
     * @return string|null Normalized E.164-style value, or null if invalid.
     */
    public static function normalizePhilippineNumber(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return null;
        }

        // Strip an international access prefix such as 0063 or 00 63.
        if (str_starts_with($digits, '0063')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '63')) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $national = $digits;
        } else {
            return null;
        }

        // Mobile: 9XXXXXXXXX (10 digits).
        if (strlen($national) === 10 && str_starts_with($national, '9')) {
            return '+63' . $national;
        }

        // Landline: area code + subscriber number, 8–10 national digits,
        // never starting with 0 or 9.
        $length = strlen($national);
        if ($length >= 8 && $length <= 10 && !str_starts_with($national, '0') && !str_starts_with($national, '9')) {
            return '+63' . $national;
        }

        return null;
    }
}
