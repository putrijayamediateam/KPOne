<?php

namespace App\Domain\Queue\Emergency;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Models\QueueRedZoneClaim;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class EmergencyAlertService
{
    public const PERMISSION = 'queue.emergency.respond.branch';

    /** Caller owns its source transaction and visit/queue locks. */
    public function triageLocked(User $actor, Visit $visit, QueueEntry $entry, VisitTriageAssessment $triage): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        if ($triage->chosen_zone === 'red' && $entry->status === QueueEntry::STATUS_WAITING && $visit->status === Visit::STATUS_REGISTERED) {
            if (! $this->open($entry->id, true)) {
                $this->raiseLocked($visit, $entry, $triage, 'clinical', $actor);
            }
        } else {
            $this->closeLocked($entry, $triage->chosen_zone !== 'red' ? 'zone_lowered' : 'left_waiting');
        }
    }

    public function closeLocked(QueueEntry $entry, string $code): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $alert = $this->open($entry->id, true);
        if (! $alert) {
            return;
        }
        $alert->forceFill(['closed_at' => now()->utc()->startOfSecond(), 'close_code' => $code, 'lock_version' => $alert->lock_version + 1])->save();
        $this->event($alert, 'system_closed', null, (string) Str::uuid(), null, null, $alert->closed_at->format('Y-m-d H:i:sP'), $code);
    }

    public function open(int $entryId, bool $lock = false): ?EmergencyAlert
    {
        return EmergencyAlert::query()->where('queue_entry_id', $entryId)->whereNull('closed_at')->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private function raiseLocked(Visit $visit, QueueEntry $entry, VisitTriageAssessment $triage, string $kind, ?User $actor): EmergencyAlert
    {
        $floor = (int) DB::table('queue_red_zone_claims')->where('queue_entry_id', $entry->id)->max('id');
        $id = DB::table('queue_emergency_alerts')->insertGetId([
            'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'visit_id' => $visit->id,
            'queue_entry_id' => $entry->id, 'raised_triage_assessment_id' => $triage->id, 'raised_kind' => $kind,
            'raised_at' => $kind === 'clinical' ? $triage->recorded_at->format('Y-m-d H:i:sP') : DB::raw("date_trunc('second',statement_timestamp())"),
            'claim_floor_id' => $floor, 'lock_version' => 1,
        ]);
        $alert = EmergencyAlert::query()->findOrFail($id);
        $key = $kind === 'activation'
            ? Uuid::uuid5(Uuid::NAMESPACE_URL, "kpone:emergency:activation:{$visit->organisation_id}:{$entry->id}:{$triage->id}")->toString()
            : (string) Str::uuid();
        $this->event($alert, $kind === 'clinical' ? 'raised' : 'activated', $actor, $key, null, null, $alert->raised_at->format('Y-m-d H:i:sP'));

        return $alert;
    }

    /** @param array<string,mixed> $input */
    public function take(User $actor, Visit $visit, array $input): QueueRedZoneClaim
    {
        $claim = $this->act($actor, $visit, $input, 'taken');
        if (! $claim) {
            throw new \LogicException('Take must return its canonical claim.');
        }

        return $claim;
    }

    /** @param array<string,mixed> $input
     * @return array{episodeId:int,lockVersion:int,seenByMe:bool}
     */
    public function seen(User $actor, Visit $visit, array $input): array
    {
        return DB::transaction(function () use ($actor, $visit, $input): array {
            $this->act($actor, $visit, $input, 'seen');
            // The outer transaction retains the actor/visit/episode locks until this response is built.
            $alert = EmergencyAlert::query()->whereKey($input['episode_id'])->firstOrFail();

            return ['episodeId' => $alert->id, 'lockVersion' => $alert->lock_version, 'seenByMe' => true];
        }, 3);
    }

    /** @param array<string,mixed> $input */
    private function act(User $actor, Visit $visit, array $input, string $operation): ?QueueRedZoneClaim
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer', 'min:1'], 'episode_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        abort_unless($branch && $branch->id === (int) $data['expected_branch_id'] && $branch->id === $visit->branch_id && $actor->organisation_id === $visit->organisation_id, 403);
        $seenDoctor = $visit->assigned_doctor_user_id;

        return DB::transaction(function () use ($actor, $visit, $branch, $data, $operation, $seenDoctor): ?QueueRedZoneClaim {
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $seenDoctor]);
            $user = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            $branches = app(BranchAccessService::class);
            abort_unless($user->is_active && $user->hasRole('resident_doctor') && $user->can(self::PERMISSION) && $branches->activeBranch($user)?->id === $branch->id && $branches->canSelect($user, $branch), 403);
            $lockedVisit = Visit::query()->whereKey($visit->id)->where('organisation_id', $user->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $entry = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
            $alert = $this->open($entry->id, true);
            $latest = VisitTriageAssessment::query()->where('visit_id', $visit->id)->latest('sequence')->first();
            if (! $alert || $alert->id !== (int) $data['episode_id'] || $entry->status !== 'waiting' || $lockedVisit->status !== 'registered' || $lockedVisit->visit_type !== 'consultation' || $latest?->chosen_zone !== 'red') {
                $this->refuse('This emergency alert changed. Reload before responding.');
            }
            $hash = hash_hmac('sha256', json_encode([$user->organisation_id, $user->id, $entry->id, $alert->id, $operation, (int) $data['lock_version']], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $retry = DB::table('queue_emergency_alert_events')->where('organisation_id', $user->organisation_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($retry) {
                if ($retry->actor_user_id !== $user->id || $retry->emergency_alert_id !== $alert->id || $retry->event_code !== $operation || ! hash_equals($retry->payload_hash ?? '', $hash)) {
                    $this->refuse('This retry key belongs to a different response. Reload before responding.');
                }

                return $operation === 'taken' ? QueueRedZoneClaim::query()->whereKey($retry->claim_id)->firstOrFail() : null;
            }
            if ($lockedVisit->assigned_doctor_user_id !== $seenDoctor || $alert->lock_version !== (int) $data['lock_version']) {
                $this->refuse('This emergency alert changed. Reload before responding.');
            }
            if ($operation === 'seen') {
                if (DB::table('queue_emergency_alert_events')->where('emergency_alert_id', $alert->id)->where('actor_user_id', $user->id)->where('event_code', 'seen')->exists()) {
                    $this->refuse('Your Seen response is already recorded. Reload the alert.');
                }
                $alert->forceFill(['lock_version' => $alert->lock_version + 1])->save();
                $this->event($alert, 'seen', $user, $data['idempotency_key'], $hash);

                return null;
            }
            $claim = $alert->taken_claim_id ? QueueRedZoneClaim::query()->find($alert->taken_claim_id) : null;
            if ($claim && $claim->claimed_by_user_id === $lockedVisit->assigned_doctor_user_id) {
                $this->refuse('This patient has already been taken. Reload the alert.');
            }
            app(VisitDoctorEligibilityService::class)->lockAndValidate($user->id, $branch, now($branch->timezone)->toDateString());
            if (ConsultationLocks::doctorBusy($user, $lockedVisit)) {
                $this->refuse('Put your current consultation On Hold before taking this patient.');
            }
            $claim = app(RedZoneAlertService::class)->claimLocked($user, $lockedVisit, $entry, $branch);
            $alert->forceFill(['taken_claim_id' => $claim->id, 'lock_version' => $alert->lock_version + 1])->save();
            $this->event($alert, 'taken', $user, $data['idempotency_key'], $hash, $claim->id, $claim->claimed_at->format('Y-m-d H:i:sP'));

            return $claim;
        }, 3);
    }

    /** @return array{created:int,skipped:int,remaining:int,invalidOpen:int} */
    public function activate(Branch $branch, bool $execute = false): array
    {
        abort_unless($branch->is_active && $branch->organisation->is_active, 422);
        $query = QueueEntry::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('status', 'waiting');
        $maximum = (int) (clone $query)->max('id');
        $created = 0;
        $skipped = 0;
        $query->where('id', '<=', $maximum)->orderBy('id')->chunkById(100, function ($entries) use ($branch, $execute, &$created, &$skipped): void {
            foreach ($entries as $candidate) {
                $visit = Visit::query()->findOrFail($candidate->visit_id);
                $doctorId = $visit->assigned_doctor_user_id;
                $result = DB::transaction(function () use ($branch, $execute, $visit, $doctorId): bool {
                    abort_unless(Branch::query()->whereKey($branch->id)->where('is_active', true)->whereHas('organisation', fn ($q) => $q->where('is_active', true))->exists(), 422);
                    ConsultationLocks::users($branch->organisation_id, [$doctorId]);
                    $v = Visit::query()->whereKey($visit->id)->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
                    $q = QueueEntry::query()->where('visit_id', $v->id)->lockForUpdate()->firstOrFail();
                    if ($v->assigned_doctor_user_id !== $doctorId) {
                        return false;
                    }
                    $t = VisitTriageAssessment::query()->where('visit_id', $v->id)->latest('sequence')->first();
                    if ($v->status !== 'registered' || $v->visit_type !== 'consultation' || $q->status !== 'waiting' || $t?->chosen_zone !== 'red' || $this->open($q->id, true)) {
                        return false;
                    }
                    if ($execute) {
                        $this->raiseLocked($v, $q, $t, 'activation', null);
                    }

                    return true;
                }, 3);
                $result ? $created++ : $skipped++;
            }
        });
        $remaining = QueueEntry::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('status', 'waiting')
            ->whereHas('visit', fn ($q) => $q->where('status', 'registered')->where('visit_type', 'consultation'))
            ->whereRaw("(SELECT chosen_zone FROM visit_triage_assessments t WHERE t.visit_id=queue_entries.visit_id ORDER BY sequence DESC LIMIT 1)='red'")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('queue_emergency_alerts')->whereColumn('queue_entry_id', 'queue_entries.id')->whereNull('closed_at'))->count();

        $invalidOpen = DB::table('queue_emergency_alerts as a')->leftJoin('queue_entries as q', 'q.id', '=', 'a.queue_entry_id')->leftJoin('visits as v', 'v.id', '=', 'a.visit_id')
            ->where('a.organisation_id', $branch->organisation_id)->where('a.branch_id', $branch->id)->whereNull('a.closed_at')
            ->whereRaw("(q.id IS NULL OR v.id IS NULL OR q.visit_id<>a.visit_id OR q.organisation_id<>a.organisation_id OR q.branch_id<>a.branch_id OR v.organisation_id<>a.organisation_id OR v.branch_id<>a.branch_id OR q.status<>'waiting' OR v.status<>'registered' OR v.visit_type<>'consultation' OR (SELECT chosen_zone FROM visit_triage_assessments t WHERE t.visit_id=a.visit_id ORDER BY sequence DESC LIMIT 1) IS DISTINCT FROM 'red')")->count();

        return compact('created', 'skipped', 'remaining', 'invalidOpen');
    }

    private function event(EmergencyAlert $alert, string $code, ?User $actor, string $key, ?string $hash = null, ?int $claimId = null, ?string $at = null, ?string $close = null): void
    {
        DB::table('queue_emergency_alert_events')->insert(['emergency_alert_id' => $alert->id, 'organisation_id' => $alert->organisation_id, 'branch_id' => $alert->branch_id, 'event_code' => $code, 'actor_user_id' => $actor?->id, 'claim_id' => $claimId, 'occurred_at' => $at ?? now()->utc()->format('Y-m-d H:i:sP'), 'idempotency_key' => $key, 'payload_hash' => $hash, 'result_version' => $alert->lock_version, 'close_code' => $close]);
        abort_if(app(AuditRecorder::class)->record('queue.emergency.'.$code, $alert, ['queue_entry_id' => $alert->queue_entry_id, 'record_version' => $alert->lock_version, 'claim_id' => $claimId, 'close_code' => $close], $actor, organisationId: $alert->organisation_id) === null, 503);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['queue' => $message]);
    }
}
