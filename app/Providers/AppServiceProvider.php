<?php

namespace App\Providers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AccessChangeActorContext;
use App\Domain\Audit\Listeners\RecordAccessChanges;
use App\Domain\Audit\Listeners\RecordAuthenticationEvents;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Policies\ClinicalEncounterPolicy;
use App\Domain\Identity\Policies\StaffPolicy;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Policies\BranchPolicy;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Policies\PatientPolicy;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Policies\QueueEntryPolicy;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Policies\VisitPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        $this->app->scoped(BranchAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuditListeners();
        $this->configurePublicCheckInRateLimiting();

        Route::bind('patient', function (string $value): Patient {
            $actor = request()->user();

            return Patient::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('patient_number', $value)
                ->firstOrFail();
        });

        Route::bind('visit', function (string $value): Visit {
            $actor = request()->user();
            $branch = app(BranchAccessService::class)->activeBranch($actor);
            abort_if($branch === null, 404);

            return Visit::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('visit_number', $value)
                ->firstOrFail();
        });

        // Historical clinical continuity is organisation-scoped after an
        // explicit clinical relationship check. It therefore cannot use the
        // active-branch Visit binding used by operational Visit routes.
        Route::bind('historicalVisit', function (string $value): Visit {
            $actor = request()->user();

            return Visit::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('visit_number', $value)
                ->firstOrFail();
        });

        Route::bind('dispensaryCase', function (string $value): DispensaryCase {
            $actor = request()->user();
            $branch = app(BranchAccessService::class)->activeBranch($actor);
            abort_if($branch === null, 404);

            return DispensaryCase::query()->where('public_id', $value)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
        });
        Route::bind('item', fn (string $value): DispensaryItem => DispensaryItem::query()->where('public_id', $value)->where('organisation_id', request()->user()->organisation_id)->firstOrFail());
        Route::bind('exception', fn (string $value): DispensaryItemException => DispensaryItemException::query()->where('public_id', $value)->where('organisation_id', request()->user()->organisation_id)->firstOrFail());
    }

    private function configurePublicCheckInRateLimiting(): void
    {
        RateLimiter::for('public-checkin-view', function (Request $request): array {
            $linkKey = hash('sha256', (string) $request->route('token'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(300)->by($ip),
                Limit::perMinute(120)->by($ip.'|'.$linkKey),
                Limit::perMinute(1000)->by($linkKey),
            ];
        });
    }

    protected function configureAuthorization(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(ClinicalEncounter::class, ClinicalEncounterPolicy::class);
        Gate::policy(User::class, StaffPolicy::class);
        Gate::policy(Patient::class, PatientPolicy::class);
        Gate::policy(QueueEntry::class, QueueEntryPolicy::class);
        Gate::policy(Visit::class, VisitPolicy::class);
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
