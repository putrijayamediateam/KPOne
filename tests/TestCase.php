<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Permission;
use Tests\Support\IsolatedTestStorage;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        IsolatedTestStorage::beforeBootstrap();
        $app = parent::createApplication();
        IsolatedTestStorage::configure($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (config('filesystems.disks', []) as $disk) {
                IsolatedTestStorage::assertContained($disk['root'] ?? '');
            }
        }
        parent::tearDown();
    }

    /** @param string|list<string> $permissions */
    protected function grantPermissions(User $user, string|array $permissions): User
    {
        foreach ((array) $permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
