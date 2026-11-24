<?php

/**
 * CI-SPLIT: writes phpunit.shard.xml, a copy of phpunit.xml whose only test suite ("Shard") lists this shard's share
 * of the Unit and Feature test files. Usage: php scripts/ci/make-shard-config.php <shard 1..N> <shards N> [--list]
 *
 * The files are exactly the ones the Unit and Feature suites of phpunit.xml run (every *Test.php under tests/Unit and
 * tests/Feature, less the Contention files that Feature excludes). They are dealt out by size, largest first, to
 * the lightest shard so far, which is deterministic for one commit. Every test file lands in exactly one shard;
 * the same rule is checked by tests/Unit/CiShardingTest.php and, on the run's own results, by the CI aggregator.
 */
$root = dirname(__DIR__, 2);

require_once __DIR__.'/shard-lib.php';

$arguments = array_values(array_filter($argv, fn (string $a): bool => ! str_starts_with($a, '--')));
$shard = (int) ($arguments[1] ?? 0);
$total = (int) ($arguments[2] ?? 0);

if ($total < 1 || $shard < 1 || $shard > $total) {
    fwrite(STDERR, "Usage: php scripts/ci/make-shard-config.php <shard> <shards> [--list]\n");
    exit(2);
}

$files = ci_shard_files($root, $total)[$shard - 1];

if (in_array('--list', $argv, true)) {
    echo implode("\n", $files), "\n";
    exit(0);
}

$document = new DOMDocument;
$document->preserveWhiteSpace = false;
$document->formatOutput = true;
$document->load($root.'/phpunit.xml');

$suites = $document->getElementsByTagName('testsuites')->item(0);
while ($suites->firstChild !== null) {
    $suites->removeChild($suites->firstChild);
}

$suite = $document->createElement('testsuite');
$suite->setAttribute('name', 'Shard');
foreach ($files as $file) {
    $suite->appendChild($document->createElement('file', $file));
}
$suites->appendChild($suite);

$document->save($root.'/phpunit.shard.xml');
fwrite(STDOUT, sprintf("Shard %d of %d: %d test files\n", $shard, $total, count($files)));
