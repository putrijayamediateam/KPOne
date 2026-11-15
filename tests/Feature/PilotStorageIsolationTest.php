<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\IsolatedTestStorage;
use Tests\TestCase;

class PilotStorageIsolationTest extends TestCase
{
    public function test_every_configured_and_fake_disk_is_inside_process_temporary_storage(): void
    {
        $temp = realpath(sys_get_temp_dir()).'/';
        foreach (config('filesystems.disks') as $name => $disk) {
            $this->assertSame('local', $disk['driver']);
            $this->assertFalse($disk['serve']);
            $this->assertStringStartsWith($temp, $disk['root']);
            IsolatedTestStorage::assertContained($disk['root']);
            Storage::disk($name)->put('sentinel', 'Synthetic test bytes');
            IsolatedTestStorage::assertContained(Storage::disk($name)->path('sentinel'));
            Storage::fake($name);
            IsolatedTestStorage::assertContained(Storage::disk($name)->path('sentinel'));
        }
    }

    public function test_forked_worker_cleanup_cannot_remove_parent_view_cache(): void
    {
        $root = IsolatedTestStorage::root();
        $this->assertSame($root.'/framework/views', config('view.compiled'));
        $this->assertDirectoryExists(config('view.compiled'));
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork storage guard fixture.');
        }
        if ($pid === 0) {
            IsolatedTestStorage::cleanup();
            exit(0);
        }
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertDirectoryExists($root.'/framework/views');
        $this->assertSame('Synthetic rendered view', app('blade.compiler')->render('Synthetic rendered view'));
    }

    public function test_cleanup_guard_refuses_application_storage_and_traversal(): void
    {
        foreach ([base_path('storage/app/private'), IsolatedTestStorage::root().'/../outside'] as $path) {
            try {
                IsolatedTestStorage::assertContained($path);
                $this->fail('Unsafe storage path accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('escaped', $exception->getMessage());
            }
        }
    }
}
