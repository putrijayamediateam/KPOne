<?php

namespace App\Http\Middleware;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\BranchAccessService;
use App\Domain\Access\WorkspaceLandingService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->routeIs('public-checkin.show')) {
            return [
                'auth' => null,
                'branchContext' => null,
                'workspace' => null,
            ];
        }

        if ($request->routeIs('queue.search') && $request->expectsJson() && ! $request->header('X-Inertia')) {
            return parent::share($request);
        }

        $user = $request->user();
        $branchContext = null;
        $workspace = null;

        if ($user) {
            $branches = app(BranchAccessService::class);
            $availableBranches = $branches->availableBranches($user);
            $activeBranch = $branches->activeBranch($user);
            $user->loadMissing('organisation');

            $branchContext = [
                'active' => $activeBranch?->only(['id', 'code', 'name']),
                'available' => $availableBranches->map->only(['id', 'code', 'name'])->values(),
                'canSwitch' => $user->can('branch_context.switch.branch') || $user->can('branch_context.switch.organisation'),
            ];

            $landing = app(WorkspaceLandingService::class);
            $canEnterClinic = $landing->canEnterClinic($user);
            $workspace = [
                'defaultUrl' => route('workspace', absolute: false),
                'canEnterClinic' => $canEnterClinic,
                'navigation' => [
                    'registration' => $user->can('visits.view.branch'),
                    'consultation' => $user->can('queue.view.own') || $user->can('queue.view.branch'),
                    'patientRecords' => $user->can('patients.search.organisation'),
                    'panelWork' => app(BillingWorkAccess::class)->panel($user),
                    'financeWork' => app(BillingWorkAccess::class)->finance($user),
                    'staff' => $user->can('staff.view.own') || $user->can('staff.view.branch') || $user->can('staff.view.organisation'),
                    'branches' => $user->can('branches.view.branch') || $user->can('branches.view.organisation'),
                    'accessControl' => $user->can('access.view.organisation'),
                    'auditLogs' => $user->can('audit.view.organisation'),
                    'publicCheckInLinks' => $user->can('public_checkin_links.manage.organisation'),
                ],
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'is_active' => $user->is_active,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'organisation' => $user->organisation->only(['id', 'code', 'name']),
                ] : null,
                'permissions' => $user?->getAllPermissions()->pluck('name')->values() ?? [],
            ],
            'branchContext' => $branchContext,
            'workspace' => $workspace,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
