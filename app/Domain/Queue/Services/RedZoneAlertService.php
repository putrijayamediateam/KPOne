<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Emergency\EmergencyAlert;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Models\QueueRedZoneClaim;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Q-16b (owner decisions 25 Nov 2026): the red-zone alert and "I'll take it".
 *
 * Alerts cover red Waiting consultations, including retained not-ready flags. Lifecycle episodes coexist with legacy rows during activation.
 * It is shown to every resident doctor at the branch and to the triage recorders (a narrow TRI-1c exception, owner-confirmed): only
 * the queue number, the minutes waiting and who took it. Never a name, reading, reason or zone label, and never the TV or QR.
 *
 * Taking it assigns the patient to the taking doctor so the doctor can call them. It never calls the patient. The first press wins
 * under the queue entry lock; the same doctor's retry succeeds; another doctor is refused with who took it.
 */
class RedZoneAlertService
{
    /** Same effective-taken rule as the response mapper; untaken must survive the bounded global response. */
    private const EFFECTIVE_TAKEN_RANK = <<<'SQL'
        CASE WHEN EXISTS (
            SELECT 1 FROM queue_red_zone_claims c
            WHERE c.queue_entry_id = queue_entries.id
              AND c.id = (SELECT max(latest.id) FROM queue_red_zone_claims latest WHERE latest.queue_entry_id = queue_entries.id)
              AND c.claimed_by_user_id = (SELECT v.assigned_doctor_user_id FROM visits v WHERE v.id = queue_entries.visit_id)
              AND (
                  NOT EXISTS (SELECT 1 FROM queue_emergency_alerts a WHERE a.queue_entry_id = queue_entries.id AND a.closed_at IS NULL)
                  OR EXISTS (SELECT 1 FROM queue_emergency_alerts a WHERE a.queue_entry_id = queue_entries.id AND a.closed_at IS NULL AND a.taken_claim_id = c.id)
              )
        ) THEN 1 ELSE 0 END
        SQL;

    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private AuditRecorder $audit,
    ) {}

    /** Resident doctors who may take, and the triage recorders who may see. */
    public function audience(User $actor): bool
    {
        return $actor->is_active && ($this->mayTake($actor) || $actor->can(TriagePermissions::RECORD_BRANCH));
    }

    public function mayTake(User $actor): bool
    {
        return $actor->is_active && $actor->hasRole('resident_doctor') && $actor->can(EmergencyAlertService::PERMISSION);
    }

    /** @return list<array{visitNumber: string, queueNumber: string, waitingMinutes: int, takenBy: string|null, mine: bool, canTake: bool, episodeId: int|null, lockVersion: int|null, alertStartedAt: string|null, seenByMe: bool|null, legacy: bool, alertOrigin: string|null}> */
    public function alerts(User $actor, int $expectedBranchId): array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $expectedBranchId || ! $this->audience($actor)) {
            throw new AuthorizationException('You may not view the alerts for this branch.');
        }
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $entries = $this->redWaiting(QueueEntry::query()
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)
            ->whereDate('queue_entries.operational_date', '<=', $today))
            ->with('visit:id,visit_number,assigned_doctor_user_id')
            ->orderByRaw(self::EFFECTIVE_TAKEN_RANK)
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM queue_emergency_alerts a WHERE a.queue_entry_id=queue_entries.id AND a.closed_at IS NULL) THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE((SELECT a.raised_at FROM queue_emergency_alerts a WHERE a.queue_entry_id=queue_entries.id AND a.closed_at IS NULL), queue_entries.queued_at)')
            ->orderBy('queue_entries.id')
            ->limit(25)->get();
        if ($entries->isEmpty()) {
            return [];
        }
        $claims = QueueRedZoneClaim::query()
            ->whereIn('queue_entry_id', $entries->modelKeys())
            ->whereRaw('id = (select max(c.id) from queue_red_zone_claims c where c.queue_entry_id = queue_red_zone_claims.queue_entry_id)')
            ->get()->keyBy('queue_entry_id');
        $names = User::query()->whereIn('id', $claims->pluck('claimed_by_user_id')->unique())->pluck('name', 'id');
        $episodes = EmergencyAlert::query()->whereIn('queue_entry_id', $entries->modelKeys())->whereNull('closed_at')->get()->keyBy('queue_entry_id');
        $seen = DB::table('queue_emergency_alert_events')->whereIn('emergency_alert_id', $episodes->modelKeys())->where('event_code', 'seen')->where('actor_user_id', $actor->id)->pluck('emergency_alert_id')->all();
        $mayTake = $this->mayTake($actor);

        return array_values($entries->map(function (QueueEntry $entry) use ($claims, $names, $mayTake, $actor, $episodes, $seen): array {
            $episode = $episodes->get($entry->id);
            $claim = $claims->get($entry->id);
            if ($episode && (! $claim || $claim->id !== $episode->taken_claim_id)) {
                $claim = null;
            }
            $taken = $claim !== null && (int) $claim->claimed_by_user_id === (int) $entry->visit->assigned_doctor_user_id;

            return [
                'visitNumber' => $entry->visit->visit_number,
                'queueNumber' => QueueNumberFormat::format($entry->queue_number),
                'waitingMinutes' => (int) max(0, $entry->queued_at->diffInMinutes(now(), true)),
                'takenBy' => $taken ? (isset($names[$claim->claimed_by_user_id]) ? (string) $names[$claim->claimed_by_user_id] : null) : null,
                'mine' => $taken && (int) $claim->claimed_by_user_id === $actor->id,
                'canTake' => $mayTake && ! $taken,
                'episodeId' => $episode?->id,
                'lockVersion' => $episode?->lock_version,
                'alertStartedAt' => $episode?->raised_at->toIso8601String(),
                'seenByMe' => $episode ? in_array($episode->id, $seen, true) : null,
                'legacy' => $episode === null,
                'alertOrigin' => $episode?->raised_kind,
            ];
        })->all());
    }

    /** The visit must be the actor's choice to take; see the class note for the lock order and the rules.
     * @param  array<string,mixed>  $command
     */
    public function take(User $actor, Visit $visit, int $expectedBranchId, array $command = []): QueueRedZoneClaim
    {
        if (isset($command['episode_id'])) {
            return app(EmergencyAlertService::class)->take($actor, $visit, [...$command, 'expected_branch_id' => $expectedBranchId]);
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $expectedBranchId) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Reload the page.']);
        }
        if (! $this->mayTake($actor) || $visit->branch_id !== $branch->id) {
            throw new AuthorizationException('You may not take this patient.');
        }
        $seenDoctorId = $visit->assigned_doctor_user_id;

        return DB::transaction(function () use ($actor, $visit, $branch, $seenDoctorId): QueueRedZoneClaim {
            // ConsultationLocks order: users (ascending id), patient, visit, queue entry.
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $seenDoctorId]);
            $lockedActor = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if (! $this->mayTake($lockedActor)) {
                throw new AuthorizationException('You may not take this patient.');
            }
            $lockedVisit = Visit::query()->whereKey($visit->id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->lockForUpdate()->firstOrFail();
            $lockedEntry = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
            if (app(EmergencyAlertService::class)->open($lockedEntry->id, true)) {
                throw ValidationException::withMessages(['queue' => 'This alert now has acknowledgement tracking. Reload before responding.']);
            }
            if ($this->branches->activeBranch($lockedActor)?->id !== $branch->id || ! $this->branches->canSelect($lockedActor, $branch)) {
                throw new AuthorizationException('The active branch changed.');
            }

            if ($lockedVisit->assigned_doctor_user_id !== $seenDoctorId) {
                throw ValidationException::withMessages(['queue' => 'This patient changed. Reload the Queue.']);
            }
            $stillRed = $lockedVisit->status === Visit::STATUS_REGISTERED && $lockedVisit->visit_type === 'consultation'
                && $lockedEntry->status === QueueEntry::STATUS_WAITING
                && $this->redWaiting(QueueEntry::query()->whereKey($lockedEntry->id))->exists();
            if (! $stillRed) {
                throw ValidationException::withMessages(['queue' => 'This patient is no longer a red-zone alert. Reload the Queue.']);
            }

            $current = QueueRedZoneClaim::query()->where('queue_entry_id', $lockedEntry->id)->latest('id')->first();
            if ($current !== null && (int) $current->claimed_by_user_id === (int) $lockedVisit->assigned_doctor_user_id) {
                if ((int) $current->claimed_by_user_id === $lockedActor->id) {
                    return $current; // the same doctor's retry
                }
                $name = User::query()->whereKey($current->claimed_by_user_id)->value('name');
                throw ValidationException::withMessages(['queue' => 'Already taken by '.($name ?: 'another doctor').'.']);
            }

            $today = now()->setTimezone($branch->timezone)->toDateString();
            $this->doctors->lockAndValidate($lockedActor->id, $branch, $today);

            if (ConsultationLocks::doctorBusy($lockedActor, $lockedVisit)) {
                throw ValidationException::withMessages(['queue' => 'Put your current consultation On Hold before taking this patient.']);
            }

            return $this->claimLocked($lockedActor, $lockedVisit, $lockedEntry, $branch);
        }, 3);
    }

    /** Single canonical claim writer; caller owns user/visit/queue locks and eligibility checks. */
    public function claimLocked(User $lockedActor, Visit $lockedVisit, QueueEntry $lockedEntry, Branch $branch): QueueRedZoneClaim
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $previous = $lockedVisit->assigned_doctor_user_id;
        if ($previous !== $lockedActor->id) {
            $lockedVisit->forceFill([
                'assigned_doctor_user_id' => $lockedActor->id,
                'updated_by_user_id' => $lockedActor->id,
                'lock_version' => $lockedVisit->lock_version + 1,
            ])->save();
            $changed = $this->audit->record('visit.doctor.changed', $lockedVisit, ['record_version' => $lockedVisit->lock_version], $lockedActor, $branch);
            abort_if($changed === null, 503, 'Unable to record the audit. Try again.');
        }
        $claim = (new QueueRedZoneClaim)->forceFill([
            'organisation_id' => $lockedActor->organisation_id,
            'branch_id' => $branch->id,
            'queue_entry_id' => $lockedEntry->id,
            'visit_id' => $lockedVisit->id,
            'claimed_by_user_id' => $lockedActor->id,
            'previous_doctor_user_id' => $previous,
            'claimed_at' => now()->utc(),
        ]);
        $claim->save();
        $audited = $this->audit->record('queue.red_zone_taken', $lockedEntry, [
            'record_version' => $lockedEntry->lock_version,
            'previous_doctor_user_id' => $previous,
            'claimed_by_user_id' => $lockedActor->id,
        ], $lockedActor, $branch, $lockedActor->organisation_id);
        abort_if($audited === null, 503, 'Unable to record the audit. Try again.');

        return $claim;
    }

    /**
     * Waiting consultations of today or earlier whose latest triage zone is red; retained not-ready flags do not hide alarms.
     *
     * @param  Builder<QueueEntry>  $query
     * @return Builder<QueueEntry>
     */
    private function redWaiting(Builder $query): Builder
    {
        return $query
            ->select('queue_entries.*')
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
            ->where('visits.status', Visit::STATUS_REGISTERED)
            ->where('visits.visit_type', 'consultation')
            ->whereRaw("(select t.chosen_zone from visit_triage_assessments t where t.visit_id = visits.id and t.organisation_id = visits.organisation_id order by t.sequence desc limit 1) = 'red'");
    }
}
