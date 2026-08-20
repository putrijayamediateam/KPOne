<?php

namespace App\Http\Middleware;

use App\Domain\Access\BranchAccessService;
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
        $user = $request->user();
        $branchContext = null;

        if ($user) {
            $branches = app(BranchAccessService::class);
            $availableBranches = $branches->availableBranches($user);
            $activeBranch = $branches->activeBranch($user);
            $user->loadMissing('organisation');

            $branchContext = [
                'active' => $activeBranch?->only(['id', 'code', 'name']),
                'available' => $availableBranches->map->only(['id', 'code', 'name'])->values(),
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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
