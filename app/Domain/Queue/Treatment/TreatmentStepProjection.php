<?php

namespace App\Domain\Queue\Treatment;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
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
        $steps = TreatmentStep::query()->where('organisation_id', $actor->organisation_id)
            ->whereIn('queue_entry_id', array_keys($serving))->whereNull('closed_at')->get()->keyBy('queue_entry_id');
        $branchWide = $actor->is_active && $actor->can(TreatmentStepPermissions::SET_BRANCH);
        $own = $actor->is_active && $actor->can(TreatmentStepPermissions::SET_OWN);
        $result = [];
        foreach ($serving as $id => $doctorId) {
            $step = $steps->get($id);
            $result[$id] = [
                'id' => $step?->id,
                'code' => $step?->step_code,
                'label' => $step ? TreatmentStepService::STEPS[$step->step_code] : null,
                'state' => $step?->state,
                'startedAt' => $step?->started_at->toIso8601String(),
                'doneAt' => $step?->done_at?->toIso8601String(),
                'lockVersion' => $step?->lock_version,
                // UI hint only; the service re-authorises under lock.
                'canManage' => $branchWide || ($own && $doctorId === $actor->id),
            ];
        }

        return $result;
    }

    /** Doctor-only "done" signal for the attending doctor: a cursor, whether a new done event arrived, and the
     * count of their patients whose treatment is done and not yet resumed. Queue numbers only with details.
     *
     * @return array{cursor:int,ready:bool,doneCount:int,numbers?:list<string>}
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
        $result = ['cursor' => $ceiling, 'ready' => $ready, 'doneCount' => $openDone()->count()];
        if ($details) {
            $result['numbers'] = $ready ? array_values($visible->orderBy('e.id')->limit(25)->get(['q.queue_number', 's.step_code'])
                ->map(fn ($row): string => QueueNumberFormat::format((int) $row->queue_number).' '.TreatmentStepService::STEPS[$row->step_code])->unique()->all()) : [];
        }

        return $result;
    }
}
