<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\Clinical\Triage\TriageGuidelineSign;
use App\Domain\Clinical\Triage\TriageGuidelineThreshold;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TRI-1b: the triage guideline settings page (Classic). The Director or the HR Manager enters a version and the other
 * one checks it against the signed paper copy and approves it (TRI-1 owner decision 7). Wording and limits only, never
 * patient data. Every rule lives in TriageGuidelineService.
 */
class TriageGuidelineController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $this->manager($request);
        // Organisation-wide page: dates in the active branch's time zone, or the clinic's when none is selected.
        $timezone = app(BranchAccessService::class)->activeBranch($actor)->timezone ?? 'Asia/Kuala_Lumpur';
        $versions = TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)
            ->with(['signs', 'thresholds'])->orderByDesc('version_number')->limit(20)->get();
        $names = User::query()->whereIn('id', $versions->flatMap(fn (TriageGuidelineVersion $v): array => array_filter([
            $v->entered_by_user_id, $v->approved_by_user_id, $v->discarded_by_user_id,
        ]))->unique()->all())->pluck('name', 'id');

        return Inertia::render('TriageGuideline/Index', [
            'inForce' => ($v = $versions->firstWhere('status', TriageGuidelineVersion::STATUS_APPROVED)) ? $this->detail($v, $actor, $names->all()) : null,
            'draft' => ($v = $versions->firstWhere('status', TriageGuidelineVersion::STATUS_DRAFT)) ? $this->detail($v, $actor, $names->all()) : null,
            'history' => $versions->map(fn (TriageGuidelineVersion $v): array => [
                'id' => $v->id,
                'versionNumber' => $v->version_number,
                'status' => $v->status,
                'enteredBy' => $names[$v->entered_by_user_id] ?? 'Staff member',
                'enteredAt' => $v->entered_at->setTimezone($timezone)->format('j M Y'),
                'approvedBy' => $v->approved_by_user_id ? ($names[$v->approved_by_user_id] ?? 'Staff member') : null,
                'approvedAt' => $v->approved_at?->setTimezone($timezone)->format('j M Y'),
            ])->values()->all(),
            'readings' => TriageGuidelineService::READINGS,
        ]);
    }

    public function store(Request $request, TriageGuidelineService $guidelines): RedirectResponse
    {
        $this->attempt(fn () => $guidelines->enter($request->user(), $request->all()));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guideline version entered. Someone else must check and approve it.')]);

        return to_route('triage-guideline.index');
    }

    public function approve(Request $request, int $version, TriageGuidelineService $guidelines): RedirectResponse
    {
        $this->attempt(fn () => $guidelines->approve($request->user(), $this->version($request, $version), $request->only(['lock_version', 'matches_signed_copy', 'idempotency_key'])));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guideline version approved and now in force.')]);

        return to_route('triage-guideline.index');
    }

    public function discard(Request $request, int $version, TriageGuidelineService $guidelines): RedirectResponse
    {
        $this->attempt(fn () => $guidelines->discard($request->user(), $this->version($request, $version), $request->only(['lock_version', 'idempotency_key'])));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft discarded.')]);

        return to_route('triage-guideline.index');
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('triage-guideline.index'));
        }
    }

    private function manager(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->is_active || ! $actor->can(TriagePermissions::GUIDELINE_MANAGE_ORGANISATION)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $actor;
    }

    private function version(Request $request, int $id): TriageGuidelineVersion
    {
        return TriageGuidelineVersion::query()->whereKey($id)->where('organisation_id', $this->manager($request)->organisation_id)->firstOrFail();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function detail(TriageGuidelineVersion $v, User $actor, array $names): array
    {
        return [
            'id' => $v->id,
            'versionNumber' => $v->version_number,
            'lockVersion' => $v->lock_version,
            'childAgeLimitYears' => $v->child_age_limit_years,
            'approvingDoctorName' => $v->approving_doctor_name,
            'doctorSignedOn' => $v->doctor_signed_on->format('j M Y'),
            'enteredBy' => $names[$v->entered_by_user_id] ?? 'Staff member',
            'enteredByYou' => $v->entered_by_user_id === $actor->id,
            'redFlags' => $v->signs->where('kind', TriageGuidelineSign::RED_FLAG)->sortBy('position')->pluck('label')->values()->all(),
            'yellowSigns' => $v->signs->where('kind', TriageGuidelineSign::YELLOW_SIGN)->sortBy('position')->pluck('label')->values()->all(),
            'limits' => $v->thresholds->map(fn (TriageGuidelineThreshold $t): array => [
                'reading' => $t->reading, 'age_band' => $t->age_band, 'direction' => $t->direction,
                'limit_value' => (string) $t->limit_value, 'zone' => $t->zone,
            ])->values()->all(),
        ];
    }
}
