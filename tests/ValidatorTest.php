<?php

declare(strict_types=1);

/**
 * Credential-free unit checks for the pure-logic parts of the Free Quote
 * backend. Requires no database, no SMTP, and no configuration file.
 *
 * Run:  php tests/ValidatorTest.php
 * Exit: 0 when every assertion passes, 1 otherwise.
 */

require_once __DIR__ . '/../api/src/Validator.php';
require_once __DIR__ . '/../api/src/ReferenceGenerator.php';

use JacSolar\Validator;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

echo "\n── Philippine number normalization ──\n";

$phoneCases = [
    '09171234567'      => '+639171234567',
    '0917 123 4567'    => '+639171234567',
    '0917-123-4567'    => '+639171234567',
    '+63 917 123 4567' => '+639171234567',
    '639171234567'     => '+639171234567',
    '9171234567'       => '+639171234567',
    '(02) 8123 4567'   => '+63281234567',
    '00639171234567'   => '+639171234567',
];

foreach ($phoneCases as $input => $expected) {
    check(
        sprintf('%-18s -> %s', $input, $expected),
        Validator::normalizePhilippineNumber((string) $input) === $expected
    );
}

$invalidPhones = ['', 'abc', '123', '+1 555 0100', '0000'];
foreach ($invalidPhones as $input) {
    check(
        sprintf('rejects %-12s', var_export($input, true)),
        Validator::normalizePhilippineNumber($input) === null
    );
}

echo "\n── Allowlist normalization ──\n";

check(
    'hyphen folds to en dash',
    Validator::normalizeChoice('₱5,000-₱8,000') === '₱5,000–₱8,000'
);
check(
    'em dash folds to en dash',
    Validator::normalizeChoice('₱5,000—₱8,000') === '₱5,000–₱8,000'
);
check(
    'whitespace collapses',
    Validator::normalizeChoice('  Commercial  /  Industrial  ') === 'Commercial / Industrial'
);
check(
    'unapproved value stays unapproved',
    !in_array(Validator::normalizeChoice('Warehouse'), Validator::PROPERTY_TYPES, true)
);

echo "\n── Allowlist contents ──\n";

check('6 property types', count(Validator::PROPERTY_TYPES) === 6);
check('9 bill ranges', count(Validator::BILL_RANGES) === 9);
check('Government present', in_array('Government', Validator::PROPERTY_TYPES, true));
check('Other present', in_array('Other', Validator::PROPERTY_TYPES, true));
check('Below ₱5,000 present', in_array('Below ₱5,000', Validator::BILL_RANGES, true));

echo "\n── Field validation ──\n";

$valid = [
    'full_name'            => 'Juan Dela Cruz',
    'contact_number'       => '0917 123 4567',
    'email'                => '  Juan.DelaCruz@Example.COM ',
    'project_location'     => 'Davao City',
    'electricity_provider' => 'Davao Light',
    'property_type'        => 'Residential',
    'bill_range'           => '₱8,000–₱12,000',
    'processing_consent'   => '1',
];

$v = new Validator();
check('valid payload passes', $v->validate($valid));

$clean = $v->clean();
check('email lowercased and trimmed', ($clean['email'] ?? '') === 'juan.delacruz@example.com');
check('phone normalized', ($clean['contact_number'] ?? '') === '+639171234567');
check('marketing consent defaults false', ($clean['marketing_consent'] ?? true) === false);
check('optional message is null', array_key_exists('message', $clean) && $clean['message'] === null);

$v = new Validator();
check('missing consent fails', !$v->validate(array_merge($valid, ['processing_consent' => '0'])));
check('consent error reported', array_key_exists('processing_consent', $v->errors()));

$v = new Validator();
check('bad email fails', !$v->validate(array_merge($valid, ['email' => 'not-an-email'])));

$v = new Validator();
check('short name fails', !$v->validate(array_merge($valid, ['full_name' => 'A'])));

$v = new Validator();
check('long name fails', !$v->validate(array_merge($valid, ['full_name' => str_repeat('a', 101)])));

$v = new Validator();
check('unapproved property type fails', !$v->validate(array_merge($valid, ['property_type' => 'Warehouse'])));

$v = new Validator();
check('unapproved bill range fails', !$v->validate(array_merge($valid, ['bill_range' => '₱1,000,000'])));

$v = new Validator();
check('array smuggled into scalar fails', !$v->validate(array_merge($valid, ['full_name' => ['a', 'b']])));

$v = new Validator();
check('filled honeypot fails', !$v->validate(array_merge($valid, [Validator::HONEYPOT_FIELD => 'bot'])));

$v = new Validator();
check(
    'over-long message fails',
    !$v->validate(array_merge($valid, ['message' => str_repeat('x', 2001)]))
);

$v = new Validator();
check(
    '2000-character message passes',
    $v->validate(array_merge($valid, ['message' => str_repeat('x', 2000)]))
);

$v = new Validator();
check(
    'over-long provider fails',
    !$v->validate(array_merge($valid, ['electricity_provider' => str_repeat('x', 151)]))
);

$v = new Validator();
check(
    'over-long location fails',
    !$v->validate(array_merge($valid, ['project_location' => str_repeat('x', 256)]))
);

echo "\n── Reference number format ──\n";

$date = new DateTimeImmutable('2026-07-30 10:00:00', new DateTimeZone('Asia/Manila'));

check(
    'first of the day is 00001',
    \JacSolar\ReferenceGenerator::format($date, 1) === 'JACQ-20260730-00001'
);
check(
    'five-digit padding holds',
    \JacSolar\ReferenceGenerator::format($date, 42) === 'JACQ-20260730-00042'
);
check(
    'no truncation at 99999',
    \JacSolar\ReferenceGenerator::format($date, 99999) === 'JACQ-20260730-99999'
);

echo "\n────────────────────────────────\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
