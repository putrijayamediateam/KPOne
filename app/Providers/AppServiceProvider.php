<?php

namespace App\Providers;

use App\Domain\Audit\AccessChangeActorContext;
use App\Domain\Audit\Listeners\RecordAccessChanges;
use App\Domain\Audit\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\Policies\StaffPolicy;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Policies\BranchPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AccessChangeActorContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuditListeners();
    }

    protected function configureAuthorization(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
    }

    protected function configureAuditListeners(): void
    {
        Event::listen(Login::class, [RecordAuthenticationEvents::class, 'login']);
        Event::listen(Logout::class, [RecordAuthenticationEvents::class, 'logout']);
        Event::listen(Failed::class, [RecordAuthenticationEvents::class, 'failed']);
        Event::listen(RoleAttachedEvent::class, [RecordAccessChanges::class, 'roleAttached']);
        Event::listen(RoleDetachedEvent::class, [RecordAccessChanges::class, 'roleDetached']);
        Event::listen(PermissionAttachedEvent::class, [RecordAccessChanges::class, 'permissionAttached']);
        Event::listen(PermissionDetachedEvent::class, [RecordAccessChanges::class, 'permissionDetached']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
