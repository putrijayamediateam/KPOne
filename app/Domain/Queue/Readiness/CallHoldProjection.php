<?php

namespace App\Domain\Queue\Readiness;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CallHoldProjection
{
    /** Already-authorised private board rows only. No flag fields are added to TV/QR models or feeds.
     * @param  list<int>  $entryIds
     * @return array<int,array<string,mixed>>
     */
    public function rows(User $actor, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $flags = CallHold::query()->where('organisation_id', $actor->organisation_id)->whereIn('queue_entry_id', $entryIds)->orderBy('id')->get()->groupBy('queue_entry_id');
        $entries = DB::table('queue_entries')->join('visits', 'visits.id', '=', 'queue_entries.visit_id')->where('queue_entries.organisation_id', $actor->organisation_id)->whereIn('queue_entries.id', $entryIds)->where('visits.visit_type', 'consultation')->where('visits.organisation_id', $actor->organisation_id)->select('queue_entries.id', 'queue_entries.status', 'visits.assigned_doctor_user_id')->get()->keyBy('id');
        $result = [];
        foreach ($entryIds as $id) {
            $entry = $entries->get($id);
            if (! $entry) {
                continue;
            }
            $flag = $flags->get($id)?->last();
            $active = $flag?->state === 'active';
            $result[$id] = [
                'id' => $flag?->id, 'lockVersion' => $flag?->lock_version, 'active' => $active, 'overridden' => $flag?->state === 'overridden',
                'reason' => $flag ? CallHoldService::REASONS[$flag->reason_code] : null,
                'canSet' => $entry->status === 'waiting' && $actor->can(CallHoldPermissions::SET),
                'canClear' => $entry->status === 'waiting' && ($actor->can(CallHoldPermissions::SET) || $actor->can(CallHoldPermissions::CLEAR)),
                'canOverride' => $entry->assigned_doctor_user_id === $actor->id && $actor->can(CallHoldPermissions::OVERRIDE),
            ];
        }

        return $result;
    }

    /** Identity-free clear-event signal. Details are requested only by a queue board and limited to the assigned doctor.
     * @return array{cursor:int,ready:bool,numbers?:list<string>}
     */
    public function updates(User $actor, int $expectedBranchId, ?int $cursor, bool $details): array
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->id === $expectedBranchId && $branches->canSelect($actor, $branch), 403);
        $events = DB::table('queue_call_hold_events as e')->join('queue_call_holds as h', 'h.id', '=', 'e.call_hold_id')
            ->where('h.organisation_id', $actor->organisation_id)->where('h.branch_id', $branch->id)->where('e.event_type', 'cleared')->where('e.doctor_user_id', $actor->id);
        if (! $actor->can('queue.view.own')) {
            return $details ? ['cursor' => 0, 'ready' => false, 'numbers' => []] : ['cursor' => 0, 'ready' => false];
        }
        $ceiling = (int) (clone $events)->max('e.id');
        $visible = (clone $events)->join('queue_entries as q', 'q.id', '=', 'h.queue_entry_id')->join('visits as v', 'v.id', '=', 'q.visit_id')
            ->where('v.organisation_id', $actor->organisation_id)->where('v.branch_id', $branch->id)->where('v.assigned_doctor_user_id', $actor->id)
            ->where('v.status', 'registered')->where('q.status', 'waiting')->whereDate('q.operational_date', '<=', now()->setTimezone($branch->timezone)->toDateString())
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('queue_call_holds as active_hold')->whereColumn('active_hold.queue_entry_id', 'q.id')->whereNull('active_hold.closed_at'))
            ->where('e.id', '>', $cursor ?? $ceiling)->where('e.id', '<=', $ceiling);
        $ready = $cursor !== null && (clone $visible)->exists();
        $result = ['cursor' => $ceiling, 'ready' => $ready];
        if ($details) {
            $result['numbers'] = $ready ? array_values($visible->orderBy('e.id')->limit(25)->pluck('q.queue_number')->map(fn ($number): string => QueueNumberFormat::format((int) $number))->unique()->all()) : [];
        }

        return $result;
    }
}
