<?php

namespace App\Http\Middleware;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\BranchAccessService;
use App\Domain\Access\WorkspaceLandingService;
use App\Domain\Patient\Models\PublicPatientIntake;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    private const AUTHENTICATION_HISTORY_SESSION_KEY = 'inertia.authentication_history_boundary.v2';

    private const AUTHENTICATION_HISTORY_VERSION = 2;

    private const AUTHENTICATION_HISTORY_FINGERPRINT_PURPOSE = 'kpone.inertia.authentication-history.session.v2';

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $this->synchroniseAuthenticationHistoryBoundary($request);

        return parent::handle($request, $next);
    }

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
        if ($request->routeIs('public-checkin.show', 'public-intake.status')) {
            return [
                'auth' => null,
                'authHistoryBoundary' => $this->authenticationHistoryBoundaryProp($request),
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
                    'registrationReview' => $user->can('public_intakes.review.branch'),
                    'consultation' => $user->can('queue.view.own') || $user->can('queue.view.branch'),
                    'inventory' => $user->can('inventory.view.branch') && $activeBranch !== null,
                    'patientRecords' => $user->can('patients.search.organisation'),
                    'panelWork' => app(BillingWorkAccess::class)->panel($user),
                    'financeWork' => app(BillingWorkAccess::class)->finance($user),
                    'staff' => $user->can('staff.view.own') || $user->can('staff.view.branch') || $user->can('staff.view.organisation'),
                    'branches' => $user->can('branches.view.branch') || $user->can('branches.view.organisation'),
                    'accessControl' => $user->can('access.view.organisation'),
                    'auditLogs' => $user->can('audit.view.organisation'),
                    'publicCheckInLinks' => $user->can('public_checkin_links.manage.organisation')
                        || $user->can('public_checkin_links.manage.branch'),
                ],
                'pendingIntakes' => $activeBranch && $user->can('public_intakes.review.branch')
                    ? PublicPatientIntake::query()
                        ->where('organisation_id', $user->organisation_id)
                        ->where('branch_id', $activeBranch->id)
                        ->whereIn('status', [
                            PublicPatientIntake::STATUS_PENDING,
                            PublicPatientIntake::STATUS_UNDER_REVIEW,
                            PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
                        ])->count()
                    : 0,
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
            'authHistoryBoundary' => $this->authenticationHistoryBoundaryProp($request),
            'branchContext' => $branchContext,
            'workspace' => $workspace,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    public static function currentAuthenticationHistoryEpoch(Request $request): ?string
    {
        $state = $request->session()->get(self::AUTHENTICATION_HISTORY_SESSION_KEY);

        return self::validAuthenticationHistoryState($state)
            ? Str::lower($state['epoch'])
            : null;
    }

    private function synchroniseAuthenticationHistoryBoundary(Request $request): void
    {
        $current = [
            'version' => self::AUTHENTICATION_HISTORY_VERSION,
            'principal' => $request->user() === null
                ? 'guest'
                : 'authenticated:'.(string) $request->user()->getAuthIdentifier(),
            'session_fingerprint' => $this->browserSessionFingerprint($request),
        ];
        $previous = $request->session()->get(self::AUTHENTICATION_HISTORY_SESSION_KEY);
        $validPrevious = self::validAuthenticationHistoryState($previous);
        $principalStable = $validPrevious
            && hash_equals($previous['principal'], $current['principal']);
        $sessionStable = $principalStable
            && ($previous['session_fingerprint'] === null
                || (is_string($previous['session_fingerprint'])
                    && is_string($current['session_fingerprint'])
                    && hash_equals($previous['session_fingerprint'], $current['session_fingerprint'])));

        if ($sessionStable) {
            if ($previous['session_fingerprint'] === null && $current['session_fingerprint'] !== null) {
                $request->session()->put(self::AUTHENTICATION_HISTORY_SESSION_KEY, [
                    ...$previous,
                    'session_fingerprint' => $current['session_fingerprint'],
                ]);
            }

            return;
        }

        $request->session()->put(self::AUTHENTICATION_HISTORY_SESSION_KEY, [
            ...$current,
            'epoch' => Str::lower((string) Str::uuid7()),
        ]);
        Inertia::clearHistory();
    }

    /** @return array{version:2,epoch:string} */
    private function authenticationHistoryBoundaryProp(Request $request): array
    {
        $epoch = self::currentAuthenticationHistoryEpoch($request);
        abort_unless($epoch !== null, 500);

        return [
            'version' => self::AUTHENTICATION_HISTORY_VERSION,
            'epoch' => $epoch,
        ];
    }

    private function browserSessionFingerprint(Request $request): ?string
    {
        $cookieName = config('session.cookie');
        $browserSessionId = is_string($cookieName) && $cookieName !== ''
            ? $request->cookie($cookieName)
            : null;

        if (! is_string($browserSessionId) || $browserSessionId === '') {
            return null;
        }

        $applicationKey = config('app.key');
        abort_unless(is_string($applicationKey) && $applicationKey !== '', 500);

        return hash_hmac(
            'sha256',
            self::AUTHENTICATION_HISTORY_FINGERPRINT_PURPOSE.':'.$browserSessionId,
            $applicationKey,
        );
    }

    private static function validAuthenticationHistoryState(mixed $state): bool
    {
        return is_array($state)
            && ($state['version'] ?? null) === self::AUTHENTICATION_HISTORY_VERSION
            && is_string($state['principal'] ?? null)
            && ($state['principal'] === 'guest' || preg_match('/\Aauthenticated:[1-9]\d*\z/', $state['principal']) === 1)
            && (($state['session_fingerprint'] ?? null) === null
                || (is_string($state['session_fingerprint'])
                    && preg_match('/\A[0-9a-f]{64}\z/', $state['session_fingerprint']) === 1))
            && is_string($state['epoch'] ?? null)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $state['epoch']) === 1;
    }
}
