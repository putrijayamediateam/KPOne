<?php

namespace App\Domain\Queue\Emergency;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Identity remains governed by ordinary board authority, never emergency-response permission alone. */
final class EmergencyLaneProjection
{
    /** @return Builder<QueueEntry> */
    public function query(User $actor, Branch $branch): Builder
    {
        $query = QueueEntry::query()->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)->where('queue_entries.status', 'waiting')
            ->whereDate('queue_entries.operational_date', '<=', now($branch->timezone)->toDateString())
            ->whereHas('visit', fn ($q) => $q->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('status', 'registered')->where('visit_type', 'consultation'))
            ->whereRaw("(SELECT t.chosen_zone FROM visit_triage_assessments t WHERE t.visit_id=queue_entries.visit_id ORDER BY t.sequence DESC LIMIT 1)='red'");
        $branches = app(BranchAccessService::class);
        if (! $actor->is_active || $actor->organisation_id !== $branch->organisation_id || $branches->activeBranch($actor)?->id !== $branch->id
            || ! $branches->canSelect($actor, $branch) || (! $actor->can('queue.view.branch') && ! $actor->can('queue.view.own'))) {
            return $query->whereRaw('false');
        }
        if (! $actor->can('queue.view.branch')) {
            $query->whereHas('visit', fn ($q) => $q->where('assigned_doctor_user_id', $actor->id));
        }

        return $query;
    }

    /** Independent of normal board filters and pages: an emergency cannot disappear on page two.
     * @return array{data:list<array<string,mixed>>,total:int,currentPage:int,lastPage:int,perPage:int,hasMore:bool}
     */
    public function snapshot(User $actor, Branch $branch, int $page = 1): array
    {
        $result = $this->read($actor, $branch, $page);

        unset($result['entries'], $result['exclusionQuery']);

        return $result;
    }

    /** Internal hydration result: entries never leave the directory serializer.
     * @return array{data:list<array<string,mixed>>,total:int,currentPage:int,lastPage:int,perPage:int,hasMore:bool,entries:Collection<int,QueueEntry>,exclusionQuery:Builder<QueueEntry>}
     */
    public function read(User $actor, Branch $branch, int $page = 1): array
    {
        $query = $this->query($actor, $branch);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 25));
        $page = min(max(1, $page), $lastPage);
        $metadata = ['total' => $total, 'currentPage' => $page, 'lastPage' => $lastPage, 'perPage' => 25, 'hasMore' => $page < $lastPage];
        $exclusionQuery = (clone $query)->select('queue_entries.visit_id');
        if ($total === 0) {
            return ['data' => [], ...$metadata, 'entries' => new Collection, 'exclusionQuery' => $exclusionQuery];
        }
        $entries = (clone $query)->with(['visit.patient', 'visit.assignedDoctor'])
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM queue_emergency_alerts a WHERE a.queue_entry_id=queue_entries.id AND a.closed_at IS NULL) THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE((SELECT a.raised_at FROM queue_emergency_alerts a WHERE a.queue_entry_id=queue_entries.id AND a.closed_at IS NULL),queue_entries.queued_at)')
            ->orderBy('queue_entries.id')->forPage($page, 25)->get();
        if ($entries->isEmpty()) {
            return ['data' => [], ...$metadata, 'entries' => $entries, 'exclusionQuery' => $exclusionQuery];
        }
        $episodes = EmergencyAlert::query()->whereIn('queue_entry_id', $entries->modelKeys())->whereNull('closed_at')->get()->keyBy('queue_entry_id');
        $claims = DB::table('queue_red_zone_claims')->whereIn('id', $episodes->pluck('taken_claim_id')->filter())->get()->keyBy('id');
        $names = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $claims->pluck('claimed_by_user_id'))->pluck('name', 'id');
        $rows = [];
        foreach ($entries as $entry) {
            $episode = $episodes->get($entry->id);
            $claim = $episode?->taken_claim_id ? $claims->get($episode->taken_claim_id) : null;
            $taken = $claim && $claim->claimed_by_user_id === $entry->visit->assigned_doctor_user_id;
            $rows[] = [
                'visitNumber' => $entry->visit->visit_number,
                'queueNumber' => QueueNumberFormat::format($entry->queue_number),
                'patientName' => $entry->visit->patient->full_name,
                'assignedDoctorName' => $entry->visit->assignedDoctor?->name,
                'waitingMinutes' => max(0, (int) $entry->queued_at->diffInMinutes(now()->utc())),
                'operationalDate' => $entry->operational_date->toDateString(),
                'episodeId' => $episode?->id,
                'alertStartedAt' => $episode?->raised_at->toIso8601String(),
                'alertOrigin' => $episode?->raised_kind,
                'takenBy' => $taken ? $names->get($claim->claimed_by_user_id) : null,
                'takenAt' => $taken ? CarbonImmutable::parse($claim->claimed_at, 'UTC')->toIso8601String() : null,
            ];
        }

        return ['data' => $rows, ...$metadata, 'entries' => $entries, 'exclusionQuery' => $exclusionQuery];
    }
}
