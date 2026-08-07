<?php

declare(strict_types=1);

/**
 * LOCAL-ONLY concurrency regression test for ReferenceGenerator.
 *
 * Spawns N concurrent PHP workers that each allocate M reference numbers
 * against a throwaway database, then asserts that the allocations are unique,
 * contiguous, and completed without deadlocks.
 *
 * This test exists because an earlier three-statement allocator
 * (INSERT IGNORE -> SELECT ... FOR UPDATE -> UPDATE) produced
 * SQLSTATE[40001] "Deadlock found when trying to get lock" under contention.
 * The current single-statement upsert must never regress to that pattern.
 *
 * Required environment variables:
 *   JACQ_TEST_DB_HOST, JACQ_TEST_DB_PORT, JACQ_TEST_DB_NAME,
 *   JACQ_TEST_DB_USER, JACQ_TEST_DB_PASS
 *
 * Run:  JACQ_TEST_DB_... php tests/concurrency_local.php
 * Exit: 0 when every assertion passes, 1 otherwise.
 *
 * Requires the `pcntl` extension. Never upload this file to Hostinger.
 */

const WORKERS            = 8;
const ALLOCATIONS_EACH   = 10;
const TEST_DATE          = '2026-08-15';

$required = [
    'JACQ_TEST_DB_HOST',
    'JACQ_TEST_DB_PORT',
    'JACQ_TEST_DB_NAME',
    'JACQ_TEST_DB_USER',
    'JACQ_TEST_DB_PASS',
];

foreach ($required as $variable) {
    if (getenv($variable) === false) {
        fwrite(STDERR, "Missing environment variable: {$variable}\n");
        exit(1);
    }
}

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "The pcntl extension is required for this test.\n");
    exit(1);
}

function testPdo(): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('JACQ_TEST_DB_HOST'),
            (int) getenv('JACQ_TEST_DB_PORT'),
            getenv('JACQ_TEST_DB_NAME')
        ),
        (string) getenv('JACQ_TEST_DB_USER'),
        (string) getenv('JACQ_TEST_DB_PASS'),
        [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function bootModules(PDO $pdo): void
{
    $base = __DIR__ . '/../api/src/';

    require_once $base . 'Config.php';
    require_once $base . 'Response.php';
    require_once $base . 'Database.php';
    require_once $base . 'ReferenceGenerator.php';

    $reflection = new ReflectionClass(JacSolar\Database::class);
    $property   = $reflection->getProperty('pdo');
    $property->setAccessible(true);
    $property->setValue(null, $pdo);
}

// Reset the counter for the test date only.
$setup = testPdo();
$reset = $setup->prepare('DELETE FROM quote_reference_counters WHERE date_ph = :d');
$reset->execute([':d' => TEST_DATE]);
$setup = null;

$outputDir = sys_get_temp_dir() . '/jacq_conc_' . bin2hex(random_bytes(4));
mkdir($outputDir, 0700, true);

$childPids = [];

for ($worker = 0; $worker < WORKERS; $worker++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "Failed to fork worker {$worker}.\n");
        exit(1);
    }

    if ($pid === 0) {
        // Child: fresh connection, then allocate.
        $pdo = testPdo();
        bootModules($pdo);

        $allocated = [];
        $errors    = [];
        $moment    = new DateTimeImmutable(
            TEST_DATE . ' 09:00:00',
            new DateTimeZone('Asia/Manila')
        );

        for ($i = 0; $i < ALLOCATIONS_EACH; $i++) {
            try {
                JacSolar\Database::begin();
                $allocated[] = JacSolar\ReferenceGenerator::next($moment);
                usleep(random_int(500, 4000));
                JacSolar\Database::commit();
            } catch (Throwable $e) {
                JacSolar\Database::rollBack();
                $errors[] = $e->getMessage();
            }
        }

        file_put_contents(
            $outputDir . '/worker_' . $worker . '.json',
            json_encode(['allocated' => $allocated, 'errors' => $errors])
        );

        exit(0);
    }

    $childPids[] = $pid;
}

foreach ($childPids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Collect results.
$allocated = [];
$errors    = [];

foreach (glob($outputDir . '/worker_*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    $allocated = array_merge($allocated, $data['allocated'] ?? []);
    $errors    = array_merge($errors, $data['errors'] ?? []);
    unlink($file);
}
rmdir($outputDir);

$expected = WORKERS * ALLOCATIONS_EACH;
$unique   = array_unique($allocated);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

printf("\n── Concurrency: %d workers x %d allocations ──\n", WORKERS, ALLOCATIONS_EACH);

check(
    'no errors or deadlocks',
    $errors === [],
    count($errors) . ' error(s), first: ' . (string) ($errors[0] ?? '')
);
check(
    'every allocation completed',
    count($allocated) === $expected,
    sprintf('got %d of %d', count($allocated), $expected)
);
check(
    'no duplicate reference numbers',
    count($unique) === count($allocated),
    sprintf('%d duplicate(s)', count($allocated) - count($unique))
);

// Sequence numbers must be exactly 1..expected with no gaps.
$sequences = array_map(
    static fn (string $reference): int => (int) substr($reference, -5),
    $allocated
);
sort($sequences);

check(
    'sequence is contiguous with no gaps',
    $sequences === range(1, $expected),
    sprintf('range %d..%d', $sequences[0] ?? 0, end($sequences) ?: 0)
);

$verify = testPdo();
$stmt   = $verify->prepare(
    'SELECT last_sequence FROM quote_reference_counters WHERE date_ph = :d'
);
$stmt->execute([':d' => TEST_DATE]);
$counter = (int) ($stmt->fetchColumn() ?: 0);

check(
    'counter row matches allocation count',
    $counter === $expected,
    sprintf('counter=%d expected=%d', $counter, $expected)
);

$prefix = 'JACQ-' . str_replace('-', '', TEST_DATE) . '-';
check(
    'all references use the approved format',
    count(array_filter(
        $allocated,
        static fn (string $r): bool => (bool) preg_match('/^JACQ-\d{8}-\d{5}$/', $r)
    )) === count($allocated)
);
check(
    'all references carry the Philippine test date',
    count(array_filter(
        $allocated,
        static fn (string $r): bool => str_starts_with($r, $prefix)
    )) === count($allocated)
);

echo "\n────────────────────────────────\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
