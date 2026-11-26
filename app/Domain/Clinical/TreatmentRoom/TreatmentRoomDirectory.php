<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class TreatmentRoomDirectory
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return array<string,mixed> */
    public function snapshot(User $actor): array
    {
        [$actor, $branch] = $this->scope($actor);
        $entries = QueueEntry::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('status', QueueEntry::STATUS_SERVING)
            ->whereHas('visit', fn ($q) => $q->where('visit_type', 'consultation')->where('status', Visit::STATUS_REGISTERED))
            ->where(function ($q) use ($branch) {
                $q->whereExists(fn ($steps) => $steps->selectRaw('1')->from('queue_treatment_steps')->whereColumn('queue_treatment_steps.queue_entry_id', 'queue_entries.id')->whereNull('closed_at'))
                    ->orWhereExists(fn ($calls) => $calls->selectRaw('1')->from('queue_calls')->whereColumn('queue_calls.queue_entry_id', 'queue_entries.id')->where('service', QueueCall::SERVICE_TREATMENT)->where('operational_date', now($branch->timezone)->toDateString()));
            })->with(['visit.patient', 'visit.clinicalEncounter.activeHold'])->orderBy('queued_at')->orderBy('id')->get();
        $steps = TreatmentStep::query()->whereIn('queue_entry_id', $entries->modelKeys())->orderByDesc('id')->get()->groupBy('queue_entry_id');
        $recorded = TreatmentRoomRecord::query()->whereIn('queue_entry_id', $entries->modelKeys())->whereNull('supersedes_record_id')->pluck('treatment_step_id')->all();

        return [
            'branch' => $branch->only(['id', 'name', 'code', 'timezone']),
            'canRecord' => $actor->can(TreatmentRoomPermissions::RECORD_BRANCH),
            'canCallTreatment' => $actor->can(TreatmentRoomPermissions::TREATMENT_CALL_BRANCH),
            'procedures' => $this->options(TreatmentRoomVocabulary::PROCEDURES),
            'reasons' => $this->options(TreatmentRoomVocabulary::REASONS),
            'rows' => $entries->map(fn (QueueEntry $entry): array => $this->row($actor, $entry, $steps->get($entry->id, collect()), $recorded, $branch->timezone))->values()->all(),
        ];
    }

    /** Current branch context for a selected visit, including readonly history after Serving ends.
     * @return array<string,mixed>
     */
    public function context(User $actor, Visit $visit): array
    {
        [$actor, $branch] = $this->scope($actor);
        $entry = QueueEntry::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('visit_id', $visit->id)->with(['visit.patient', 'visit.clinicalEncounter.activeHold'])->firstOrFail();
        $steps = TreatmentStep::query()->where('queue_entry_id', $entry->id)->orderByDesc('id')->get();
        $recorded = TreatmentRoomRecord::query()->where('queue_entry_id', $entry->id)->whereNull('supersedes_record_id')->pluck('treatment_step_id')->all();

        return $this->row($actor, $entry, $steps, $recorded, $branch->timezone);
    }

    /** @return array{User,Branch} */
    private function scope(User $actor): array
    {
        $actor = $actor->fresh();
        $branch = $actor ? $this->branches->activeBranch($actor) : null;
        if (! $actor || ! $actor->is_active || ! $branch || ! $actor->can(TreatmentRoomPermissions::VIEW_BRANCH) || ! $this->branches->canSelect($actor, $branch)) {
            throw new AuthorizationException('You may not view this treatment room.');
        }

        return [$actor, $branch];
    }

    /** @param Collection<int,TreatmentStep> $steps
     * @param  array<int,int|null>  $recorded
     * @return array<string,mixed>
     */
    private function row(User $actor, QueueEntry $entry, Collection $steps, array $recorded, string $timezone): array
    {
        $visit = $entry->visit;
        $serving = $visit->visit_type === 'consultation' && $visit->status === Visit::STATUS_REGISTERED && $entry->status === QueueEntry::STATUS_SERVING;
        $canRecord = $actor->can(TreatmentRoomPermissions::RECORD_BRANCH) && $serving;

        return [
            'branchId' => $entry->branch_id, 'branchTimezone' => $timezone, 'visitStatus' => $visit->status, 'queueStatus' => $entry->status, 'isHeld' => $visit->clinicalEncounter?->activeHold !== null,
            'visitNumber' => $visit->visit_number, 'patientNumber' => $visit->patient->patient_number, 'patientName' => $visit->patient->full_name,
            'queueNumber' => QueueNumberFormat::format($entry->queue_number), 'visitLockVersion' => $visit->lock_version, 'queueLockVersion' => $entry->lock_version,
            'canRecord' => $canRecord, 'canCallTreatment' => $serving && $actor->can(TreatmentRoomPermissions::TREATMENT_CALL_BRANCH),
            'steps' => $steps->map(fn (TreatmentStep $step): array => [
                'id' => $step->id, 'code' => $step->step_code, 'label' => TreatmentStepService::label($step->step_code), 'state' => $step->state, 'lockVersion' => $step->lock_version,
                'startedAt' => $step->started_at->toIso8601String(), 'doneAt' => $step->done_at?->toIso8601String(),
                'hasOutcome' => in_array($step->id, $recorded, true),
                'canRecordOutcome' => $canRecord && ! in_array($step->id, $recorded, true) && in_array($step->state, ['started', 'done', 'acknowledged', 'cancelled'], true),
            ])->values()->all(),
        ];
    }

    /** @param array<string,string> $labels
     * @return list<array{value:string,label:string}>
     */
    private function options(array $labels): array
    {
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
