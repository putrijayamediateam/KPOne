<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/ci/shard-lib.php';

/**
 * CI-SPLIT: the backend CI job runs the Unit and Feature files in shards. Every file that phpunit.xml runs in the
 * parallel Unit and Feature suites must land in exactly one shard, for any shard count, and the Contention files
 * must stay out of them (they run in their own shards).
 */
class CiShardingTest extends TestCase
{
    public function test_every_unit_and_feature_file_is_in_exactly_one_shard(): void
    {
        $root = dirname(__DIR__, 2);

        $everything = [];
        foreach (['tests/Unit', 'tests/Feature'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (str_ends_with($relative, 'Test.php')) {
                    $everything[] = $relative;
                }
            }
        }

        $config = simplexml_load_file($root.'/phpunit.xml');
        $contention = [];
        foreach ($config->testsuites->testsuite as $suite) {
            if ((string) $suite['name'] === 'Contention') {
                $contention = array_map('strval', iterator_to_array($suite->file, false));
            }
        }
        $expected = array_values(array_diff($everything, $contention));
        sort($expected);
        $this->assertNotEmpty($expected);

        foreach ([1, 2, 3, 4, 5] as $total) {
            $shards = ci_shard_files($root, $total);
            $this->assertCount($total, $shards);

            $dealt = array_merge(...$shards);
            $this->assertSame([], array_keys(array_filter(array_count_values($dealt), fn (int $n): bool => $n > 1)), "A file is in two of $total shards.");
            sort($dealt);
            $this->assertSame($expected, $dealt, "The $total shards must hold exactly the Unit and Feature files, less the Contention files.");
            $this->assertSame([], array_values(array_intersect($dealt, $contention)), 'A Contention file leaked into a Unit/Feature shard.');
        }
    }

    public function test_each_shard_runs_its_unit_files_before_its_feature_files(): void
    {
        foreach (ci_shard_files(dirname(__DIR__, 2), 3) as $files) {
            $seenFeature = false;
            foreach ($files as $file) {
                $isUnit = str_starts_with($file, 'tests/Unit/');
                $this->assertFalse($isUnit && $seenFeature, "$file would run after a Feature file; see the ordering note in scripts/ci/shard-lib.php.");
                $seenFeature = $seenFeature || ! $isUnit;
            }
        }
    }

    public function test_shards_are_deterministic_and_reasonably_balanced(): void
    {
        $root = dirname(__DIR__, 2);

        $first = ci_shard_files($root, 3);
        $this->assertSame($first, ci_shard_files($root, 3));

        $sizes = array_map(fn (array $files): int => array_sum(array_map(fn (string $f): int => (int) filesize($root.'/'.$f), $files)), $first);
        $this->assertLessThan(1.15, max($sizes) / min($sizes), 'Shards are dealt by size, so the heaviest may not exceed the lightest by 15%.');
    }
}
