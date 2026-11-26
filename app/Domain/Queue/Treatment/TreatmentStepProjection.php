<?php

namespace App\Domain\Queue\Treatment;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPolicy;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomVocabulary;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TreatmentStepProjection
{
    /** Already-authorised private board rows only (Serving consultations). Never added to TV or QR feeds.
     * Reuses the board's loaded entries and visits, so a poll costs one query, and none when nothing is Serving.
     *
     * @param  iterable<QueueEntry>  $entries  entries with their visit loaded
     * @return array<int,array<string,mixed>>
     */
    public function rows(User $actor, iterable $entries): array
    {
        $serving = [];
        foreach ($entries as $entry) {
            // Queue entries are consultation-only (OTC has its own list); PostgreSQL checks the type on insert.
            if ($entry->organisation_id === $actor->organisation_id && $entry->status === QueueEntry::STATUS_SERVING) {
                $serving[$entry->id] = $entry->visit->assigned_doctor_user_id;
            }
        }
        if ($serving === []) {
            return [];
        }
        // Q-2c: an entry can have one step in progress and finished steps still waiting for the doctor.
        $steps = TreatmentStep::query()->where('organisation_id', $actor->organisation_id)
            ->whereIn('queue_entry_id', array_keys($serving))->whereNull('closed_at')->orderBy('id')->get()->groupBy('queue_entry_id');
        $branchWide = $actor->is_active && $actor->can(TreatmentStepPermissions::SET_BRANCH);
        $own = $actor->is_active && $actor->can(TreatmentStepPermissions::SET_OWN);
        $outcomes = $this->outcomes($actor, array_values($steps->flatten()->map(fn (TreatmentStep $step): int => $step->id)->all()));
        $result = [];
        foreach ($serving as $id => $doctorId) {
            $open = $steps->get($id, collect());
            // The main step: the one in progress, else the most recently finished one.
            $step = $open->firstWhere('state', TreatmentStep::STATE_STARTED)
                ?? $open->sortBy([['done_at', 'desc'], ['id', 'desc']])->first();
            $waiting = $open->filter(fn (TreatmentStep $s): bool => $s->state === TreatmentStep::STATE_DONE && $s->id !== $step?->id)
                ->sortBy([['done_at', 'asc'], ['id', 'asc']])->values();
            $result[$id] = [
                'id' => $step?->id,
                'code' => $step?->step_code,
                'label' => $step ? TreatmentStepService::label($step->step_code) : null,
                'state' => $step?->state,
                'startedAt' => $step?->started_at->toIso8601String(),
                'doneAt' => $step?->done_at?->toIso8601String(),
                'lockVersion' => $step?->lock_version,
                // UI hint only; the service re-authorises under lock.
                'canManage' => $branchWide || ($own && $doctorId === $actor->id),
            ];
            if ($step && isset($outcomes[$step->id])) {
                $result[$id]['outcome'] = $outcomes[$step->id];
            }
            if ($waiting->isNotEmpty()) {
                // Q-2c: other finished steps still waiting for the doctor, oldest first. Only sent when there are any.
                $result[$id]['waiting'] = $waiting->map(fn (TreatmentStep $s): array => [
                    ...(isset($outcomes[$s->id]) ? ['outcome' => $outcomes[$s->id]] : []),
                    'label' => TreatmentStepService::label($s->step_code),
                    'doneAt' => $s->done_at?->toIso8601String(),
                ])->all();
            }
        }

        return $result;
    }

    /** Doctor-only "done" signal for the attending doctor: a cursor, whether a new done event arrived, and the
     * count of their patients whose treatment is done and not yet resumed. Queue numbers only with details.
     *
     * @return array{cursor:int,ready:bool,doneCount:int,numbers?:list<string>,outcomes?:list<array<string,string|null>>}
     */
    public function updates(User $actor, int $expectedBranchId, ?int $cursor, bool $details): array
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->id === $expectedBranchId && $branches->canSelect($actor, $branch), 403);
        if (! $actor->can('queue.view.own')) {
            return $details ? ['cursor' => 0, 'ready' => false, 'doneCount' => 0, 'numbers' => []] : ['cursor' => 0, 'ready' => false, 'doneCount' => 0];
        }
        $openDone = fn () => DB::table('queue_treatment_steps as s')
            ->join('queue_entries as q', 'q.id', '=', 's.queue_entry_id')->join('visits as v', 'v.id', '=', 'q.visit_id')
            ->where('s.organisation_id', $actor->organisation_id)->where('s.branch_id', $branch->id)
            ->whereNull('s.closed_at')->where('s.state', TreatmentStep::STATE_DONE)
            ->where('q.status', QueueEntry::STATUS_SERVING)->where('v.status', 'registered')
            ->where('v.organisation_id', $actor->organisation_id)->where('v.branch_id', $branch->id)
            ->where('v.assigned_doctor_user_id', $actor->id);
        $events = DB::table('queue_treatment_step_events as e')->join('queue_treatment_steps as s', 's.id', '=', 'e.treatment_step_id')
            ->where('s.organisation_id', $actor->organisation_id)->where('s.branch_id', $branch->id)
            ->where('e.event_type', 'done')->where('e.doctor_user_id', $actor->id);
        $ceiling = (int) (clone $events)->max('e.id');
        $visible = $openDone()->join('queue_treatment_step_events as e', 'e.treatment_step_id', '=', 's.id')
            ->where('e.event_type', 'done')->where('e.doctor_user_id', $actor->id)
            ->where('e.id', '>', $cursor ?? $ceiling)->where('e.id', '<=', $ceiling);
        $ready = $cursor !== null && (clone $visible)->exists();
        // Q-2c: one patient can have several finished steps waiting; the count is of patients.
        $result = ['cursor' => $ceiling, 'ready' => $ready, 'doneCount' => $openDone()->distinct()->count('s.queue_entry_id')];
        if ($details) {
            $visibleRows = $ready ? $visible->orderBy('e.id')->limit(25)->get(['q.queue_number', 's.step_code', 's.id']) : collect();
            $result['numbers'] = array_values($visibleRows->map(fn ($row): string => QueueNumberFormat::format((int) $row->queue_number).' '.TreatmentStepService::label($row->step_code))->unique()->all());
            $outcomes = $this->outcomes($actor, array_values($visibleRows->map(fn ($row): int => $row->id)->all()));
            if ($outcomes !== []) {
                $result['outcomes'] = array_values($visibleRows->filter(fn ($row): bool => isset($outcomes[$row->id]))
                    ->map(fn ($row): array => ['number' => QueueNumberFormat::format((int) $row->queue_number), ...$outcomes[$row->id]])->values()->all());
            }
        }

        return $result;
    }

    /** Current immutable outcomes for an already scoped step set; no records enter public feeds.
     * @param  list<int>  $stepIds
     * @return array<int,array{procedureLabel:string,outcomeLabel:string,reasonLabel:?string}>
     */
    private function outcomes(User $actor, array $stepIds): array
    {
        if ($stepIds === [] || ! $actor->is_active) {
            return [];
        }
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        if (! $branch || ! $branches->canSelect($actor, $branch)) {
            return [];
        }
        $branchView = $actor->can(TreatmentRoomPermissions::VIEW_BRANCH);
        if (! $branchView && ! $actor->can('encounters.view.own')) {
            return [];
        }
        $records = TreatmentRoomRecord::query()->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->whereIn('treatment_step_id', $stepIds)
            ->when(! $branchView, fn ($q) => $q->whereExists(fn ($care) => $care->selectRaw('1')->from('clinical_encounters')
                ->whereColumn('clinical_encounters.visit_id', 'treatment_room_records.visit_id')
                ->where('attending_clinician_user_id', $actor->id)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treatment_room_records as successor')->whereColumn('successor.supersedes_record_id', 'treatment_room_records.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treatment_room_record_voids')->whereColumn('target_record_id', 'treatment_room_records.id'))->get();
        $result = [];
        $visits = Visit::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $records->pluck('visit_id'))->with(['clinicalEncounter', 'queueEntry'])->get()->keyBy('id');
        $policy = app(TreatmentRoomPolicy::class);
        foreach ($records as $record) {
            $visit = $visits->get($record->visit_id);
            if (! $visit || ! $policy->view($actor, $visit)) {
                continue;
            }
            if ($record->treatment_step_id === null) {
                continue;
            }
            $result[$record->treatment_step_id] = [
                'procedureLabel' => TreatmentRoomVocabulary::PROCEDURES[$record->procedure_code],
                'outcomeLabel' => $record->outcome_code === 'done' ? 'Done' : 'Not successful',
                'reasonLabel' => $record->reason_code ? TreatmentRoomVocabulary::REASONS[$record->reason_code] : null,
            ];
        }

        return $result;
    }
}
