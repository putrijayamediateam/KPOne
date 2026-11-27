<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Clinical\Triage\TriageViewProjection;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Q-16c (owner decisions 25 Nov 2026): the details panel of a queue board row.
 *
 * Audience, owner-confirmed: the triage recorders (CA, CA Supervisor, Medical Assistant, who hold visits.triage.record.branch) and the
 * patient's own doctor. No other role, the Director included. No new permission. The triage readings follow the same set.
 *
 * Read only. {@see self::open()} authorises, records the open with ReadOnlyReviewAudit (ids only, committed on its own before any
 * content is built; if the audit cannot be written nothing is shown), and only then builds the panel. The caller must not hold a
 * database transaction. Shows age (never the date of birth), sex, the active allergy names, the last BP, the last visit, who pays
 * with the member reference, the visit reason and the latest triage readings. No identifier, contact or clinical note.
 */
class QueueRowDetailsService
{
    public function __construct(
        private BranchAccessService $branches,
        private ReadOnlyReviewAudit $audit,
        private VisitReasonService $visitReasons,
    ) {}

    /** @return array<string, mixed> */
    public function open(User $actor, Visit $visit): array
    {
        $branch = $this->authorise($actor, $visit);
        $this->audit->record($actor, 'queue.details_viewed', $visit, ['visit' => $visit->id, 'patient' => $visit->patient_id], $branch);

        return $this->build($visit, $branch);
    }

    /**
     * @throws AuthorizationException when the actor is neither a triage recorder nor the patient's own doctor
     * @throws ModelNotFoundException when the visit is not a consultation at the actor's active branch
     */
    public function authorise(User $actor, Visit $visit): Branch
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch instanceof Branch || $visit->organisation_id !== $actor->organisation_id
            || $visit->branch_id !== $branch->id || $visit->visit_type !== 'consultation') {
            throw new ModelNotFoundException;
        }
        $recorder = $actor->can(TriagePermissions::RECORD_BRANCH);
        $ownDoctor = $actor->hasRole('resident_doctor') && $actor->can('encounters.view.own')
            && TriageViewProjection::ownDoctorId($visit) === $actor->id;
        if (! $recorder && ! $ownDoctor) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $branch;
    }

    /** Whether the actor may open the panel (for the board's row menu); never throws. */
    public function canOpen(User $actor, Visit $visit): bool
    {
        try {
            $this->authorise($actor, $visit);

            return true;
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function build(Visit $visit, Branch $branch): array
    {
        $visit->loadMissing('patient');
        $patient = $visit->patient;
        $today = now()->setTimezone($branch->timezone);

        $profile = PatientAllergyProfile::query()->where('patient_id', $patient->id)->where('organisation_id', $visit->organisation_id)->first();
        $allergies = $profile === null ? [] : PatientAllergyRecord::query()
            ->where('patient_allergy_profile_id', $profile->id)
            ->where('status', PatientAllergyRecord::STATUS_ACTIVE)
            ->orderBy('allergen_text')->limit(20)->pluck('allergen_text')->all();

        $lastBp = EncounterVitalObservation::query()
            ->join('clinical_encounters', 'clinical_encounters.id', '=', 'encounter_vital_observations.clinical_encounter_id')
            ->join('visits', 'visits.id', '=', 'clinical_encounters.visit_id')
            ->where('visits.patient_id', $patient->id)
            ->where('visits.organisation_id', $visit->organisation_id)
            ->where('visits.id', '!=', $visit->id)
            ->whereNotNull('encounter_vital_observations.systolic_bp')
            ->whereNotNull('encounter_vital_observations.diastolic_bp')
            ->orderByDesc('clinical_encounters.started_at')
            ->first(['encounter_vital_observations.systolic_bp', 'encounter_vital_observations.diastolic_bp', 'clinical_encounters.started_at']);

        $previous = Visit::query()
            ->where('patient_id', $patient->id)->where('organisation_id', $visit->organisation_id)
            ->where('id', '!=', $visit->id)->where('status', Visit::STATUS_COMPLETED)
            ->orderByDesc('completed_at')->first(['visit_number', 'completed_at']);

        $triage = VisitTriageAssessment::query()->where('visit_id', $visit->id)->orderByDesc('sequence')->first();
        $summary = $this->visitReasons->summary($visit);

        return [
            'visitNumber' => $visit->visit_number,
            'ageYears' => $patient->date_of_birth === null ? null : (int) $patient->date_of_birth->diffInYears($today, true),
            'sex' => $patient->sex,
            'allergyStatus' => $profile === null ? PatientAllergyProfile::STATUS_UNKNOWN : $profile->status,
            'allergies' => $allergies,
            'lastBp' => $lastBp === null ? null : [
                'systolic' => (int) $lastBp->systolic_bp,
                'diastolic' => (int) $lastBp->diastolic_bp,
                'on' => Carbon::parse($lastBp->getAttribute('started_at'))->setTimezone($branch->timezone)->format('j M Y'),
            ],
            'lastVisit' => $previous === null ? null : [
                'visitNumber' => $previous->visit_number,
                'on' => $previous->completed_at?->setTimezone($branch->timezone)->format('j M Y'),
            ],
            'coverageLabel' => $visit->coverage_type === 'panel' ? $visit->coverage_panel_name_snapshot : 'Self-pay',
            'memberReference' => $visit->coverage_type === 'panel' ? $visit->coverage_member_reference : null,
            'reason' => $summary,
            'triage' => $triage === null ? null : [
                'zone' => $triage->chosen_zone,
                'recordedAt' => $triage->recorded_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'readings' => collect(VisitTriageAssessment::READINGS)->mapWithKeys(fn (string $field): array => [
                    $field => $triage->getAttribute($field) === null ? null : (string) $triage->getAttribute($field),
                ])->all(),
            ],
        ];
    }
}
