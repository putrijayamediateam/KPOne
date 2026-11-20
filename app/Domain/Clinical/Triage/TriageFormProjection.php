<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TRI-1b: what the Classic triage form needs, for a CA or CA Supervisor at the visit's branch. The patient's name and
 * KP number, the age group (never the date of birth), the guideline in force (sign labels and limits only), the latest
 * triage and what the lowering rule needs. Reads only; TriageService decides every save.
 */
class TriageFormProjection
{
    public function __construct(
        private BranchAccessService $branches,
        private TriageGuidelineService $guidelines,
        private TriageService $triage,
    ) {}

    /** @return array<string, mixed> */
    public function form(User $actor, Visit $visit): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active || ! $actor->can(TriagePermissions::RECORD_BRANCH)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch instanceof Branch || $visit->organisation_id !== $actor->organisation_id || $visit->branch_id !== $branch->id) {
            throw new ModelNotFoundException;
        }
        $visit->load(['patient:id,patient_number,full_name', 'queueEntry']);
        $queue = $visit->queueEntry;
        $started = ClinicalEncounter::query()->where('visit_id', $visit->id)->exists();
        $readOnly = match (true) {
            $visit->visit_type !== 'consultation' || $visit->status !== Visit::STATUS_REGISTERED || ! $queue => 'Triage is only for a registered consultation in the queue.',
            $queue->status === QueueEntry::STATUS_SERVING && $started => 'Triage is read only once the doctor has started the consultation.',
            ! in_array($queue->status, [QueueEntry::STATUS_WAITING, QueueEntry::STATUS_SERVING], true) => 'Triage is only for a registered consultation in the queue.',
            default => null,
        };
        $guideline = $this->guidelines->inForce($actor->organisation_id);
        $latest = $this->triage->latest($visit);
        $latest?->load('recordedBy:id,name');

        return [
            'visit' => [
                'visitNumber' => $visit->visit_number,
                'patientName' => $visit->patient->full_name,
                'patientNumber' => $visit->patient->patient_number,
                'queueNumber' => $queue ? QueueNumberFormat::format($queue->queue_number) : null,
                'queueStatus' => $queue?->status,
                'priority' => $visit->priority,
            ],
            'branchId' => $branch->id,
            'readOnly' => $readOnly,
            'expected' => [
                'visitLockVersion' => $visit->lock_version,
                'queueLockVersion' => $queue?->lock_version,
                'latestTriageId' => $latest?->id,
                'guidelineVersionId' => $guideline?->id,
            ],
            'ageBand' => $guideline ? $this->triage->ageBand($visit, $branch->timezone, $guideline->child_age_limit_years) : null,
            'guideline' => $guideline ? [
                'versionNumber' => $guideline->version_number,
                'childAgeLimitYears' => $guideline->child_age_limit_years,
                'redFlags' => $this->signs($guideline, TriageGuidelineSign::RED_FLAG),
                'yellowSigns' => $this->signs($guideline, TriageGuidelineSign::YELLOW_SIGN),
                'limits' => TriageService::limits($guideline),
            ] : null,
            'latest' => $latest ? [
                'sequence' => $latest->sequence,
                'readings' => collect(VisitTriageAssessment::READINGS)->mapWithKeys(fn (string $field): array => [
                    $field => $latest->getAttribute($field) === null ? null : (string) $latest->getAttribute($field),
                ])->all(),
                'chosenZone' => $latest->chosen_zone,
                'recordedAt' => $latest->recorded_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'recordedBy' => $latest->recordedBy?->name,
            ] : null,
            'lowering' => [
                'previousZone' => VisitTriageAssessment::query()->where('visit_id', $visit->id)->whereNotNull('chosen_zone')->orderByDesc('sequence')->value('chosen_zone'),
                'hasEarlierTriage' => $latest !== null,
                'hadRedFlag' => $this->triage->hadRedFlag($visit),
                'readingsToRetake' => $this->triage->readingsToRetake($visit),
            ],
            'lowerReasons' => self::options(TriageService::LOWER_REASONS),
            'higherReasons' => self::options(TriageService::HIGHER_REASONS),
        ];
    }

    /** @return list<array{code:string, label:string}> */
    private function signs(TriageGuidelineVersion $guideline, string $kind): array
    {
        return array_values($guideline->signs->where('kind', $kind)->sortBy('position')
            ->map(fn (TriageGuidelineSign $sign): array => ['code' => $sign->code, 'label' => $sign->label])->all());
    }

    /**
     * @param  array<string, string>  $reasons
     * @return list<array{value:string, label:string}>
     */
    private static function options(array $reasons): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($reasons), $reasons);
    }
}
