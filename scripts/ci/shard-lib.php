<?php

/**
 * The Unit and Feature test files that phpunit.xml runs in parallel (everything except the serial Contention files),
 * dealt into $total shards. Pure and deterministic: same repository content, same shards. Within a shard the files
 * run Unit first, then Feature (see the note on ordering below).
 *
 * @return list<list<string>> shard index (0-based) => repository-relative paths, in a stable order
 */
function ci_shard_files(string $root, int $total): array
{
    $config = simplexml_load_file($root.'/phpunit.xml');
    $excluded = [];
    foreach ($config->testsuites->testsuite as $suite) {
        if ((string) $suite['name'] === 'Feature') {
            foreach ($suite->exclude as $exclude) {
                $excluded[(string) $exclude] = true;
            }
        }
    }

    $all = [];
    foreach (['tests/Unit', 'tests/Feature'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_ends_with($relative, 'Test.php') && ! isset($excluded[$relative])) {
                $all[$relative] = $file->getSize();
            }
        }
    }

    uksort($all, fn (string $a, string $b): int => [$all[$b], $a] <=> [$all[$a], $b]);

    $shards = array_fill(0, $total, []);
    $weights = array_fill(0, $total, 0);
    foreach ($all as $path => $size) {
        $lightest = array_search(min($weights), $weights, true);
        $shards[$lightest][] = $path;
        $weights[$lightest] += $size;
    }
    // Unit files first, then Feature, each alphabetical: the order the unsharded run used. It matters. A test that
    // uses no database trait stays on the shared kpone_test database (Laravel only gives a process its own database
    // to tests that use RefreshDatabase and the like), and TestCase rebuilds that schema for such a test once its
    // process has already run a database test. Run late, the Unit tests of two processes would rebuild it at once.
    foreach ($shards as &$list) {
        usort($list, fn (string $a, string $b): int => [str_starts_with($a, 'tests/Unit/') ? 0 : 1, $a] <=> [str_starts_with($b, 'tests/Unit/') ? 0 : 1, $b]);
    }

    return $shards;
}
