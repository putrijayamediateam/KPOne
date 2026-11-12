<?php

/**
 * The Unit and Feature test files that phpunit.xml runs in parallel (everything except the serial Contention files),
 * dealt into $total shards. Pure and deterministic: same repository content, same shards.
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
    foreach ($shards as &$list) {
        sort($list);
    }

    return $shards;
}
