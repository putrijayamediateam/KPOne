<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Test-only storage; fake disks and cleanup can never target application storage. */
final class IsolatedTestStorage
{
    private static ?string $root = null;

    private static ?int $ownerPid = null;

    public static function root(): string
    {
        if (self::$root === null) {
            $temp = realpath(sys_get_temp_dir());
            if ($temp === false) {
                throw new RuntimeException('System temporary directory is unavailable.');
            }
            self::$root = $temp.'/kpone-tests-'.getmypid().'-'.bin2hex(random_bytes(8));
            self::$ownerPid = getmypid();
            register_shutdown_function(self::cleanup(...));
        }
        foreach (['', '/framework/cache/data', '/framework/sessions', '/framework/views', '/logs'] as $suffix) {
            if (! is_dir(self::$root.$suffix) && ! mkdir(self::$root.$suffix, 0700, true) && ! is_dir(self::$root.$suffix)) {
                throw new RuntimeException('Cannot create isolated test storage.');
            }
        }

        return self::$root;
    }

    public static function beforeBootstrap(): void
    {
        $root = self::root();
        $_ENV['LARAVEL_STORAGE_PATH'] = $root;
        $_SERVER['LARAVEL_STORAGE_PATH'] = $root;
        putenv('LARAVEL_STORAGE_PATH='.$root);
    }

    public static function configure(Application $app): void
    {
        $app['config']->set('view.compiled', self::root().'/framework/views');
        foreach (array_keys($app['config']->get('filesystems.disks', [])) as $name) {
            // Remote drivers are never used by tests, even if the invoking shell configured one.
            $app['config']->set('filesystems.disks.'.$name, [
                'driver' => 'local', 'root' => self::root().'/disks/'.$name,
                'throw' => true, 'visibility' => 'private', 'serve' => false,
            ]);
        }
        Storage::forgetDisk(array_keys($app['config']->get('filesystems.disks', [])));
    }

    public static function assertContained(string $path): void
    {
        $root = self::root();
        if (! str_starts_with($path, $root.'/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)
            || str_contains($path, '\\') || is_link($root)) {
            throw new RuntimeException('Test storage or cleanup escaped the process temporary root.');
        }
    }

    public static function cleanup(): void
    {
        if (self::$root === null || self::$ownerPid !== getmypid()) {
            // Forked test workers inherit callbacks, but never own the parent root.
            return;
        }
        $root = self::$root;
        $temp = realpath(sys_get_temp_dir());
        if ($temp === false || dirname($root) !== $temp || ! str_starts_with(basename($root), 'kpone-tests-') || is_link($root)) {
            throw new RuntimeException('Refusing unsafe test storage cleanup.');
        }
        (new Filesystem)->deleteDirectory($root);
    }
}
