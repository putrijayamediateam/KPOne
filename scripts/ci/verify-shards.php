<?php

/**
 * CI-SPLIT: proves that one backend shard ran exactly the tests it was given.
 * Usage: php scripts/ci/verify-shards.php <expected test count> <junit file or directory of junit files>
 *
 * The expected count is `phpunit --list-tests` for the shard's suite; the junit file is what the run really
 * executed. They must be equal, nothing may have failed, and no test may be skipped in a Contention file (a
 * skipped PostgreSQL test is no evidence). Skips elsewhere must be in the baseline of known skips (KNOWN_SKIPS).
 * The static job separately proves that the shard lists add up to the unsharded suites.
 */
const KNOWN_SKIPS = ['Tests\Feature\Auth\RegistrationTest']; // Fortify registration is disabled on purpose.

$expected = (int) ($argv[1] ?? 0);
$target = rtrim($argv[2] ?? '', '/');
$files = is_file($target) ? [$target] : (glob($target.'/*.xml') ?: []);

if ($expected < 1 || $files === []) {
    fwrite(STDERR, "Usage: php scripts/ci/verify-shards.php <expected count> <junit file or directory>\n");
    exit(2);
}

sort($files);
$total = 0;
$problems = [];
$unexpectedSkips = [];

foreach ($files as $file) {
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        $problems[] = basename($file).' is not readable junit XML.';

        continue;
    }

    $isContention = str_starts_with(basename($file), 'contention');
    $cases = $xml->xpath('//testcase') ?: [];
    $skipped = 0;
    foreach ($cases as $case) {
        $failed = isset($case->failure) || isset($case->error);
        if ($failed) {
            $problems[] = basename($file).': '.$case['class'].'::'.$case['name'].' did not pass.';
        }
        if (isset($case->skipped)) {
            $skipped++;
            $known = false;
            foreach (KNOWN_SKIPS as $class) {
                $known = $known || str_starts_with((string) $case['class'], $class);
            }
            if ($isContention || ! $known) {
                $unexpectedSkips[] = basename($file).': '.$case['class'].'::'.$case['name'];
            }
        }
    }

    printf("%-34s %5d tests, %d skipped\n", basename($file), count($cases), $skipped);
    $total += count($cases);
}

printf("Executed: %d. Expected (listed): %d.\n", $total, $expected);

if ($total !== $expected) {
    $problems[] = "This run executed $total tests, but $expected were listed for it. A test was run twice or not at all.";
}
foreach ($unexpectedSkips as $skip) {
    $problems[] = "Skipped test not in the known baseline: $skip";
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems)."\n");
    exit(1);
}

echo "Every listed test ran once and none failed.\n";
