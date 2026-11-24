<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TRI-1e (owner, 24 Nov 2026, "ya semua"; TRI-1e-DOCTOR-VIEW sections 2 and 7): the patient's own doctor reads the
 * visit's triage record. View only: nothing here writes, and the page data carries no lock version, idempotency key or
 * expected id, so nothing on the page can be posted back.
 *
 * The own doctor is the encounter's attending clinician once a consultation exists, otherwise the visit's assigned
 * doctor, never both. The visit must be at the actor's active branch (an effective assignment). {@see self::open()}
 * authorises first, then records the open with ReadOnlyReviewAudit, and only then builds the page.
 */
class TriageViewProjection
{
    public function __construct(
        private BranchAccessService $branches,
        private ReadOnlyReviewAudit $audit,
    ) {}

    /**
     * Authorise, record the open (committed on its own), then build the page. If the audit row cannot be written,
     * ReadOnlyReviewAudit throws and nothing is shown. Called straight from the controller, never inside a transaction
     * (a source test pins that), so the audit row commits before any content is built.
     *
     * @return array<string, mixed>
     */
    public function open(User $actor, Visit $visit): array
    {
        $branch = $this->authorise($actor, $visit);
        $this->audit->record($actor, 'triage.viewed', $visit, ['visit' => $visit->id, 'patient' => $visit->patient_id], $branch);

        return $this->view($visit, $branch);
    }

    /** The own doctor of a visit, by the one TRI-1e formula. */
    public static function ownDoctorId(Visit $visit): ?int
    {
        $attending = ClinicalEncounter::query()->where('visit_id', $visit->id)->value('attending_clinician_user_id');

        return $attending !== null ? (int) $attending : $visit->assigned_doctor_user_id;
    }

    /** Whether the actor may open the view (the same check as authorise(), for links; never throws). */
    public function canView(User $actor, Visit $visit): bool
    {
        try {
            $this->authorise($actor, $visit);

            return true;
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }

    /**
     * @throws AuthorizationException when the actor is not the patient's own doctor
     * @throws ModelNotFoundException when the visit is not at the actor's active branch
     */
    public function authorise(User $actor, Visit $visit): Branch
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active || ! $actor->hasRole('resident_doctor') || ! $actor->can(TriagePermissions::VIEW_OWN)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch instanceof Branch || $visit->organisation_id !== $actor->organisation_id || $visit->branch_id !== $branch->id) {
            throw new ModelNotFoundException;
        }
        if ($visit->visit_type !== 'consultation' || self::ownDoctorId($visit) !== $actor->id) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $branch;
    }

    /** @return array<string, mixed> */
    public function view(Visit $visit, Branch $branch): array
    {
        $visit->load(['patient:id,patient_number,full_name', 'queueEntry']);
        $rows = VisitTriageAssessment::query()
            ->where('visit_id', $visit->id)
            ->orderByDesc('sequence')
            ->with(['recordedBy:id,name'])
            ->get();
        $guidelines = TriageGuidelineVersion::query()
            ->whereIn('id', $rows->pluck('guideline_version_id')->filter()->unique()->all())
            ->with('signs')
            ->get()
            ->keyBy('id');
        $reasons = TriageService::LOWER_REASONS + TriageService::HIGHER_REASONS;

        $saves = $rows->map(function (VisitTriageAssessment $row) use ($guidelines, $branch, $reasons): array {
            $guideline = $row->guideline_version_id !== null ? $guidelines->get($row->guideline_version_id) : null;
            $label = function (string $code, string $kind) use ($guideline): string {
                $sign = $guideline?->signs->first(fn (TriageGuidelineSign $s): bool => $s->kind === $kind && $s->code === $code);

                return $sign !== null ? $sign->label : "Sign no longer listed ({$code})";
            };

            return [
                'sequence' => $row->sequence,
                'recordedAt' => $row->recorded_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'recordedBy' => $row->recordedBy?->name,
                'readings' => collect(VisitTriageAssessment::READINGS)->mapWithKeys(fn (string $field): array => [
                    $field => $row->getAttribute($field) === null ? null : (string) $row->getAttribute($field),
                ])->all(),
                'guidelineVersion' => $guideline?->version_number,
                'redFlags' => array_map(fn (string $code): string => $label($code, TriageGuidelineSign::RED_FLAG), $row->red_flag_codes),
                'yellowSigns' => array_map(fn (string $code): string => $label($code, TriageGuidelineSign::YELLOW_SIGN), $row->yellow_sign_codes),
                'suggestedZone' => $row->suggested_zone,
                'chosenZone' => $row->chosen_zone,
                'reason' => $row->override_reason_code !== null ? ($reasons[$row->override_reason_code] ?? $row->override_reason_code) : null,
            ];
        })->values()->all();

        return [
            'visit' => [
                'visitNumber' => $visit->visit_number,
                'patientName' => $visit->patient->full_name,
                'patientNumber' => $visit->patient->patient_number,
                'queueNumber' => $visit->queueEntry ? QueueNumberFormat::format($visit->queueEntry->queue_number) : null,
                'priority' => $visit->priority,
            ],
            'currentZone' => $rows->first()?->chosen_zone,
            'saves' => $saves,
        ];
    }
}
