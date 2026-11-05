<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CI-PAR-1: backend tests run in parallel, each process on its own database. Worker subprocesses read the
 * configured database instead, and a forked child shares the parent's storage, so every test that starts a
 * process must sit in the serial "Contention" suite of phpunit.xml and be excluded from "Feature". This test
 * fails when one is missing.
 */
class ContentionSuiteTest extends TestCase
{
    public function test_every_test_that_starts_a_process_runs_in_the_serial_contention_suite(): void
    {
        $root = dirname(__DIR__, 2);
        $config = simplexml_load_file($root.'/phpunit.xml');
        $this->assertNotFalse($config);

        $suites = [];
        foreach ($config->testsuites->testsuite as $suite) {
            $suites[(string) $suite['name']] = $suite;
        }
        $this->assertArrayHasKey('Contention', $suites);
        $this->assertArrayHasKey('Feature', $suites);

        $listed = array_map('strval', iterator_to_array($suites['Contention']->file, false));
        $excluded = array_map('strval', iterator_to_array($suites['Feature']->exclude, false));
        sort($listed);
        sort($excluded);
        $this->assertSame($listed, $excluded, 'Each contention file is excluded from Feature, and only those.');

        // Direct starts (Symfony Process, the Process facade, proc_open, fork, shell functions) and, through
        // tests/Support, helpers that start one: a test using such a helper counts too.
        $starts = '/\bnew\s+\\\\?(?:[\w\\\\]+\\\\)?Process\s*\(|Process::(?:fromShellCommandline|run|start|pool|pipe)\b'
            .'|\b(?:proc_open|pcntl_fork|popen|shell_exec|passthru|exec|system)\s*\(/';
        $helpers = [];
        foreach ($this->phpFiles($root.'/tests/Support') as $path) {
            if (preg_match($starts, (string) file_get_contents($path)) === 1) {
                $helpers[] = basename($path, '.php');
            }
        }

        $starting = [];
        foreach (['tests/Feature', 'tests/Unit'] as $directory) {
            foreach ($this->phpFiles($root.'/'.$directory) as $path) {
                if (! str_ends_with($path, 'Test.php') || $path === __FILE__) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                $viaHelper = array_filter($helpers, fn (string $helper): bool => preg_match('/\b'.preg_quote($helper, '/').'\b/', $source) === 1);
                if (preg_match($starts, $source) === 1 || $viaHelper !== []) {
                    $starting[] = substr($path, strlen($root) + 1);
                }
            }
        }
        sort($starting);

        $this->assertNotEmpty($starting);
        $this->assertSame($starting, $listed, 'A test that starts a worker process must be listed in the Contention suite.');
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
