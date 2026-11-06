<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-7b payroll runs (HR-PLAN 16.5 and 16.6). A preparer prepares a draft (and prepares it again), enters MTD and manual
 * lines, and sends it; sending freezes it. Someone other than the preparer approves or returns it, and the approver
 * locks it. Locking records each staff claim in the run as paid with HR-6's own payment row (method payroll), so it is
 * never paid twice. Every write: account locks, a fresh permission check under lock, DB::transaction, the run row lock,
 * lock_version, an idempotency key and audit with ids, codes and counts only, never an amount.
 */
final class PayrollRunService
{
    public const MANUAL_REASONS = ['correction' => 'Correction', 'bonus' => 'Bonus', 'recovery' => 'Recovery', 'other' => 'Other'];

    public const RETURN_REASONS = ['wrong_amount' => 'Wrong amount', 'wrong_person' => 'Wrong person', 'missing_record' => 'A record is missing', 'other' => 'Other'];

    public function __construct(private RecordSupport $support, private PayrollRunCalculator $calculator) {}

    /** @param array<string, mixed> $input */
    public function saveLineTypes(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $input, $key): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_payroll_line_types:'.$actor->organisation_id]);
            $ids = [];
            $errors = [];
            foreach (['overtime_type_id' => 'earnings', 'unpaid_leave_type_id' => 'deduction', 'claim_type_id' => 'refund'] as $field => $kind) {
                $value = $input[$field] ?? null;
                if ($value === null || $value === '') {
                    $ids[$field] = null;

                    continue;
                }
                $type = is_string($value) && Str::isUuid($value) ? DB::table('hr_pay_item_types as t')->join('hr_pay_item_type_versions as v', function ($join): void {
                    $join->on('v.item_type_id', '=', 't.id')->on('v.version', '=', 't.current_version');
                })->where('t.organisation_id', $actor->organisation_id)->where('t.public_id', $value)->first(['t.id', 'v.kind', 'v.is_active']) : null;
                if (! $type instanceof \stdClass || ! $type->is_active || $type->kind !== $kind) {
                    $errors[$field] = ['earnings' => 'Choose an earnings', 'deduction' => 'Choose a deduction', 'refund' => 'Choose a refund'][$kind].' pay item type that is in use.';

                    continue;
                }
                $ids[$field] = (int) $type->id;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $hash = $this->support->hash([$actor->id, 'payroll_line_types', $ids]);
            if ($this->support->replay($actor, $key, 'payroll_line_types', $hash) !== null) {
                return;
            }
            $current = (int) DB::table('hr_payroll_line_types')->where('organisation_id', $actor->organisation_id)->max('version');
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== $current) {
                throw ValidationException::withMessages(['payroll' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            DB::table('hr_payroll_line_types')->insert(['organisation_id' => $actor->organisation_id, 'version' => $current + 1, ...$ids,
                'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'payroll_line_types', $hash, (string) ($current + 1));
            $this->support->audit->record('hr.payroll.line_types_saved', null, ['version' => $current + 1], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Prepares a regular run for a pay group and month, or an adjustment run for a locked one.
     *
     * @param  array<string, mixed>  $input
     */
    public function prepare(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $month = is_string($input['month'] ?? null) && preg_match('/\A(20\d\d)-(0[1-9]|1[0-2])\z/', (string) $input['month']) === 1 ? $input['month'].'-01' : null;
        $group = $input['pay_group_id'] ?? null;
        $kind = ($input['kind'] ?? 'regular') === 'adjustment' ? 'adjustment' : 'regular';
        $errors = [];
        if ($month === null) {
            $errors['month'] = 'Choose a month.';
        }
        if (! is_string($group) || ! Str::isUuid($group)) {
            $errors['pay_group_id'] = 'Choose a pay group.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $key, $month, $group, $kind): string {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, 'payroll_run_prepare', $group, $month, $kind]);
            if (($replay = $this->support->replay($actor, $key, 'payroll_run_prepare', $hash)) !== null) {
                return $replay;
            }
            $refusal = $this->lastApproverRefusal($actor, null);
            if ($refusal !== null) {
                throw ValidationException::withMessages(['payroll' => $refusal]);
            }
            $payGroup = DB::table('hr_pay_groups')->where('organisation_id', $actor->organisation_id)->where('public_id', $group)->lockForUpdate()->first();
            if (! $payGroup instanceof \stdClass || ! $payGroup->is_active) {
                throw ValidationException::withMessages(['pay_group_id' => 'Choose a pay group that is in use.']);
            }
            $regular = DB::table('hr_payroll_runs')->where('pay_group_id', $payGroup->id)->where('month', $month)->where('kind', 'regular')->lockForUpdate()->first();
            if ($kind === 'regular' && $regular !== null) {
                throw ValidationException::withMessages(['month' => 'This pay group already has a run for this month.']);
            }
            if ($kind === 'adjustment') {
                if ($regular === null || $regular->status !== 'locked') {
                    throw ValidationException::withMessages(['month' => 'An adjustment corrects a locked run. Lock this month\'s run first.']);
                }
                if (DB::table('hr_payroll_runs')->where('adjusts_run_id', $regular->id)->where('status', '<>', 'locked')->exists()) {
                    throw ValidationException::withMessages(['month' => 'An adjustment for this run is already open.']);
                }
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_payroll_runs')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'pay_group_id' => $payGroup->id,
                'month' => $month, 'kind' => $kind, 'adjusts_run_id' => $kind === 'adjustment' ? $regular->id : null, 'status' => 'draft',
                'group_name' => $payGroup->name, 'group_pay_day' => $payGroup->pay_day, 'prepared_by_user_id' => $actor->id, 'prepared_at' => $now,
                'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $run = DB::table('hr_payroll_runs')->where('id', $id)->first();
            $count = $this->store($actor, $run, []);
            $this->support->remember($actor, $key, 'payroll_run_prepare', $hash, $publicId);
            $this->support->audit->record('hr.payroll.run_prepared', null, ['run_id' => $id, 'kind' => $kind, 'month' => $month, 'people' => $count],
                $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function prepareAgain(User $actor, string $runId, array $input): void
    {
        $this->onDraft($actor, $runId, $input, 'payroll_run_again', [], function (User $actor, \stdClass $run): void {
            $count = $this->store($actor, $run, $this->mtd($run));
            $this->support->audit->record('hr.payroll.run_prepared', null, ['run_id' => (int) $run->id, 'kind' => $run->kind, 'month' => (string) $run->month,
                'people' => $count], $actor, null, $actor->organisation_id);
        });
    }

    /** @param array<string, mixed> $input MTD for one person, in RM; empty clears it */
    public function setMtd(User $actor, string $runId, array $input): void
    {
        $worker = $input['worker_id'] ?? null;
        if (! is_string($worker) || ! Str::isUuid($worker)) {
            abort(404);
        }
        $sen = PayrollMoney::sen($input['mtd'] ?? null, 'mtd', true, true);
        $this->onDraft($actor, $runId, $input, 'payroll_run_mtd', [$worker, $sen], function (User $actor, \stdClass $run) use ($worker, $sen): void {
            $workerId = DB::table('hr_workers')->where('organisation_id', $actor->organisation_id)->where('public_id', $worker)->value('id');
            abort_if($workerId === null || ! DB::table('hr_payroll_run_people')->where('run_id', $run->id)->where('worker_id', $workerId)->exists(), 404);
            $mtd = $this->mtd($run);
            if ($sen === null) {
                unset($mtd[(int) $workerId]);
            } else {
                $mtd[(int) $workerId] = $sen;
            }
            $this->store($actor, $run, $mtd);
            $this->support->audit->record('hr.payroll.run_mtd_set', null, ['run_id' => (int) $run->id, 'worker_id' => (int) $workerId, 'cleared' => $sen === null],
                $actor, null, $actor->organisation_id);
        });
    }

    /** @param array<string, mixed> $input */
    public function addManual(User $actor, string $runId, array $input): void
    {
        $errors = [];
        $worker = $input['worker_id'] ?? null;
        if (! is_string($worker) || ! Str::isUuid($worker)) {
            $errors['worker_id'] = 'Choose a person.';
        }
        $type = $input['item_type_id'] ?? null;
        if (! is_string($type) || ! Str::isUuid($type)) {
            $errors['item_type_id'] = 'Choose a pay item type.';
        }
        $reason = $input['reason_code'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::MANUAL_REASONS)) {
            $errors['reason_code'] = 'Choose why.';
        }
        $sen = 0;
        try {
            $sen = (int) PayrollMoney::sen($input['amount'] ?? null, 'amount');
        } catch (ValidationException $e) {
            $errors['amount'] = $e->errors()['amount'][0];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->onDraft($actor, $runId, $input, 'payroll_run_manual', [$worker, $type, $sen, $reason], function (User $actor, \stdClass $run) use ($worker, $type, $sen, $reason): void {
            $w = DB::table('hr_workers')->where('organisation_id', $actor->organisation_id)->where('public_id', $worker)->first(['id']);
            if (! $w instanceof \stdClass || ($run->kind === 'regular' && ! DB::table('hr_payroll_run_people')->where('run_id', $run->id)->where('worker_id', $w->id)->exists())) {
                throw ValidationException::withMessages(['worker_id' => 'Choose a person in this run.']);
            }
            if ($run->kind === 'adjustment' && ! DB::table('hr_payroll_run_people')->where('run_id', $run->adjusts_run_id)->where('worker_id', $w->id)->exists()) {
                throw ValidationException::withMessages(['worker_id' => 'Choose a person in the locked run this corrects.']);
            }
            $person = Worker::query()->find($w->id);
            if ($person instanceof Worker && PayrollSetupService::isManager($person)) {
                throw ValidationException::withMessages(['worker_id' => 'Pay kept outside KPOne in this version.']);
            }
            $t = DB::table('hr_pay_item_types as t')->join('hr_pay_item_type_versions as v', function ($join): void {
                $join->on('v.item_type_id', '=', 't.id')->on('v.version', '=', 't.current_version');
            })->where('t.organisation_id', $actor->organisation_id)->where('t.public_id', $type)->first(['t.id', 'v.version', 'v.is_active']);
            if (! $t instanceof \stdClass || ! $t->is_active) {
                throw ValidationException::withMessages(['item_type_id' => 'Choose a pay item type that is in use.']);
            }
            $id = DB::table('hr_payroll_manual_lines')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'run_id' => $run->id,
                'worker_id' => $w->id, 'item_type_id' => $t->id, 'item_type_version' => $t->version, 'amount_encrypted' => Crypt::encryptString((string) $sen),
                'reason_code' => $reason, 'added_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->store($actor, $run, $this->mtd($run));
            $this->support->audit->record('hr.payroll.run_manual_added', null, ['run_id' => (int) $run->id, 'manual_line_id' => $id, 'reason_code' => $reason],
                $actor, null, $actor->organisation_id);
        });
    }

    /** @param array<string, mixed> $input */
    public function removeManual(User $actor, string $runId, string $lineId, array $input): void
    {
        $this->onDraft($actor, $runId, $input, 'payroll_run_manual_remove', [$lineId], function (User $actor, \stdClass $run) use ($lineId): void {
            $line = Str::isUuid($lineId) ? DB::table('hr_payroll_manual_lines')->where('run_id', $run->id)->where('public_id', $lineId)->first(['id']) : null;
            abort_if(! $line instanceof \stdClass, 404);
            DB::table('hr_payroll_manual_lines')->where('id', $line->id)->delete();
            $this->store($actor, $run, $this->mtd($run));
            $this->support->audit->record('hr.payroll.run_manual_removed', null, ['run_id' => (int) $run->id, 'manual_line_id' => (int) $line->id],
                $actor, null, $actor->organisation_id);
        });
    }

    /**
     * Sends a draft for approval and freezes it. Refused while anyone has an error, and when anything it was prepared
     * from has changed since (prepare it again).
     *
     * @param  array<string, mixed>  $input
     */
    public function send(User $actor, string $runId, array $input): void
    {
        $this->onDraft($actor, $runId, $input, 'payroll_run_send', [], function (User $actor, \stdClass $run): void {
            $people = DB::table('hr_payroll_run_people')->where('run_id', $run->id)->get();
            if ($people->isEmpty()) {
                throw ValidationException::withMessages(['payroll' => 'Nobody is in this run. Check the pay profiles, then prepare it again.']);
            }
            if ($people->contains(fn (\stdClass $p): bool => json_decode((string) $p->errors, true) !== [])) {
                throw ValidationException::withMessages(['payroll' => 'Fix the errors in this run before you send it. Warnings don\'t stop you sending.']);
            }
            $fresh = $this->calculator->calculate($actor->organisation_id, $run, $this->mtd($run));
            if ($fresh['fingerprint'] !== $run->fingerprint) {
                throw ValidationException::withMessages(['payroll' => 'Something this run was prepared from has changed. Prepare it again, check it, then send it.']);
            }
            $now = now()->utc();
            DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'sent', 'sent_by_user_id' => $actor->id, 'sent_at' => $now,
                'versions' => json_encode($fresh['versions'], JSON_THROW_ON_ERROR), 'lock_version' => (int) $run->lock_version + 1, 'updated_at' => $now]);
            $this->action($actor, $run, 'sent');
            $this->support->audit->record('hr.payroll.run_sent', null, ['run_id' => (int) $run->id, 'people' => $people->count()], $actor, null, $actor->organisation_id);
        });
    }

    /**
     * Approve, return (to Draft, with a reason) or lock. Never by the person who prepared or sent it; only the approver
     * locks.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, string $runId, string $decision, array $input): void
    {
        if (! in_array($decision, ['approve', 'return', 'lock'], true)) {
            abort(404);
        }
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = null;
        if ($decision === 'return') {
            $reason = $input['reason'] ?? null;
            if (! is_string($reason) || ! array_key_exists($reason, self::RETURN_REASONS)) {
                throw ValidationException::withMessages(['reason' => 'Choose why it goes back.']);
            }
        }
        DB::transaction(function () use ($actor, $runId, $decision, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, PayrollPermissions::APPROVE);
            $hash = $this->support->hash([$actor->id, 'payroll_run_decide', $runId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'payroll_run_decide', $hash) !== null) {
                return;
            }
            $run = $this->run($actor, $runId);
            $this->support->assertLock($run, $input);
            $refusal = $this->personRefusal($actor, $run, $decision);
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
            $claimRefusal = $decision === 'return' ? null : $this->claimRefusal($actor, $run);
            if ($claimRefusal !== null) {
                throw ValidationException::withMessages(['payroll' => $claimRefusal]);
            }
            $now = now()->utc();
            $claims = 0;
            if ($decision === 'approve') {
                DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => $now,
                    'lock_version' => (int) $run->lock_version + 1, 'updated_at' => $now]);
            } elseif ($decision === 'return') {
                // The action row goes first, so PostgreSQL still sees who sent the run when it checks the returner.
                $this->action($actor, $run, 'returned', $reason);
                DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'draft', 'sent_by_user_id' => null, 'sent_at' => null, 'versions' => null,
                    'approved_by_user_id' => null, 'approved_at' => null, 'lock_version' => (int) $run->lock_version + 1, 'updated_at' => $now]);
            } else {
                $this->action($actor, $run, 'locked');
                $claims = $this->payClaims($actor, $run);
                DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'locked', 'locked_by_user_id' => $actor->id, 'locked_at' => $now,
                    'lock_version' => (int) $run->lock_version + 1, 'updated_at' => $now]);
            }
            if ($decision === 'approve') {
                $this->action($actor, $run, 'approved');
            }
            $this->support->remember($actor, $key, 'payroll_run_decide', $hash, (string) $run->public_id);
            $this->support->audit->record('hr.payroll.run_'.['approve' => 'approved', 'return' => 'returned', 'lock' => 'locked'][$decision], null,
                ['run_id' => (int) $run->id, 'reason_code' => $reason, 'claims_paid' => $claims], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** One rule for the service and the page. Null when the actor may take this step now. */
    public function deciderRefusal(User $actor, \stdClass $run, string $decision): ?string
    {
        return $this->personRefusal($actor, $run, $decision) ?? ($decision === 'return' ? null : $this->claimRefusal($actor, $run));
    }

    private function personRefusal(User $actor, \stdClass $run, string $decision): ?string
    {
        if (! $actor->can(PayrollPermissions::APPROVE)) {
            return 'The Director approves and locks payroll runs.';
        }
        if ((int) $run->prepared_by_user_id === $actor->id || (int) ($run->sent_by_user_id ?? 0) === $actor->id
            || DB::table('hr_payroll_run_actions')->where('run_id', $run->id)->where('action', 'prepared')->where('actor_user_id', $actor->id)->exists()) {
            return 'Someone else approves this. You prepared or sent it.';
        }
        $needed = ['approve' => ['sent'], 'return' => ['sent', 'approved'], 'lock' => ['approved']][$decision] ?? [];
        if (! in_array($run->status, $needed, true)) {
            return 'This run has moved on. Reload to see it.';
        }
        if (($decision === 'lock' || ($decision === 'return' && $run->status === 'approved')) && (int) $run->approved_by_user_id !== $actor->id) {
            return $decision === 'lock' ? 'The person who approved this run locks it.' : 'The person who approved this run sends it back.';
        }

        return null;
    }

    public const LAST_APPROVER = 'You are the only person left who can approve this run, so the HR Manager makes this change.';

    /**
     * Anyone who prepares any part of a run never approves it, so an approver may help prepare only while another active
     * approver who has not prepared this run is left (S6; B.E.R.N.I.S.S for the owner, option (a)). One rule for the
     * service and the page. Null when the actor may take a draft step, or prepare a new run when $run is null.
     */
    public function lastApproverRefusal(User $actor, ?\stdClass $run): ?string
    {
        if (! $actor->can(PayrollPermissions::APPROVE)) {
            return null;
        }
        $barred = [$actor->id];
        if ($run !== null) {
            $barred = [...$barred, (int) $run->prepared_by_user_id, (int) ($run->sent_by_user_id ?? 0),
                ...DB::table('hr_payroll_run_actions')->where('run_id', $run->id)->where('action', 'prepared')->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all()];
        }
        $others = User::permission(PayrollPermissions::APPROVE)->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->whereNotIn('id', array_values(array_unique($barred)))->exists();

        return $others ? null : self::LAST_APPROVER;
    }

    /**
     * The staff claims a run pays must still be approved and unpaid, and the person approving or locking it is never a
     * claim's claimant or approver. Checked before approval, so a run never reaches a lock nobody may take.
     */
    private function claimRefusal(User $actor, \stdClass $run): ?string
    {
        $claims = DB::table('hr_payroll_lines as l')->join('hr_staff_claims as c', 'c.id', '=', 'l.source_id')->where('l.run_id', $run->id)->where('l.kind', 'claim')
            ->orderBy('c.id')->get(['c.number', 'c.status', 'c.claimant_user_id', 'c.decided_by_user_id']);
        foreach ($claims as $claim) {
            if ($claim->status !== 'approved') {
                return "Staff claim {$claim->number} was paid or changed after this run was sent. Send the run back and prepare it again.";
            }
            if ((int) $claim->claimant_user_id === $actor->id || (int) $claim->decided_by_user_id === $actor->id) {
                return "You sent or approved staff claim {$claim->number}, so someone else approves and locks this run. If nobody else can, Finance records the claim as paid outside payroll and the run is prepared again.";
            }
        }

        return null;
    }

    /**
     * Records each staff claim in the run as paid through payroll with HR-6's own payment row, under the claim's row
     * lock, so a Finance payment of the same claim and this lock never both succeed.
     */
    private function payClaims(User $actor, \stdClass $run): int
    {
        $ids = DB::table('hr_payroll_lines')->where('run_id', $run->id)->where('kind', 'claim')->orderBy('source_id')->pluck('source_id')->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return 0;
        }
        $claims = DB::table('hr_staff_claims')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $today = CarbonImmutable::now($this->zone($actor->organisation_id))->toDateString();
        foreach ($ids as $id) {
            $claim = $claims->get($id);
            if (! $claim instanceof \stdClass || $claim->status !== 'approved') {
                throw ValidationException::withMessages(['payroll' => 'Staff claim '.($claim->number ?? '').' was paid or changed after this run was sent. Send the run back and prepare it again.']);
            }
            if ((int) $claim->claimant_user_id === $actor->id || (int) $claim->decided_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['payroll' => "You sent or approved staff claim {$claim->number}, so someone else approves and locks this run. If nobody else can, Finance records the claim as paid outside payroll and the run is prepared again."]);
            }
            DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'claim_id' => $claim->id,
                'kind' => 'payment', 'method' => 'payroll', 'paid_on' => $today, 'recorded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            DB::table('hr_staff_claims')->where('id', $claim->id)->update(['status' => 'paid', 'lock_version' => (int) $claim->lock_version + 1, 'updated_at' => now()->utc()]);
            DB::table('hr_staff_claim_actions')->insert(['organisation_id' => $actor->organisation_id, 'claim_id' => $claim->id, 'action' => 'paid',
                'actor_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->audit->record('hr.claims.paid', null, ['claim_id' => (int) $claim->id, 'method' => 'payroll', 'paid_on' => $today, 'run_id' => (int) $run->id],
                $actor, null, $actor->organisation_id);
        }

        return count($ids);
    }

    /**
     * Runs a draft-only step under the run row lock.
     *
     * @param  array<string, mixed>  $input
     * @param  list<mixed>  $parts
     */
    private function onDraft(User $actor, string $runId, array $input, string $action, array $parts, callable $step): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $runId, $input, $action, $parts, $step, $key): void {
            $actor = $this->support->lock($actor, PayrollPermissions::PREPARE);
            $hash = $this->support->hash([$actor->id, $action, $runId, ...$parts]);
            if ($this->support->replay($actor, $key, $action, $hash) !== null) {
                return;
            }
            $run = $this->run($actor, $runId);
            $this->support->assertLock($run, $input);
            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['payroll' => 'Only a draft run changes. This one has been sent.']);
            }
            $refusal = $this->lastApproverRefusal($actor, $run);
            if ($refusal !== null) {
                throw ValidationException::withMessages(['payroll' => $refusal]);
            }
            $step($actor, $run);
            $this->support->remember($actor, $key, $action, $hash, (string) $run->public_id);
        }, 3);
    }

    /**
     * Calculates and stores a draft's people and lines, replacing what it held. Returns the number of people.
     *
     * @param  array<int, int>  $mtd
     */
    private function store(User $actor, \stdClass $run, array $mtd): int
    {
        $calc = $this->calculator->calculate($actor->organisation_id, $run, $mtd);
        DB::table('hr_payroll_lines')->where('run_id', $run->id)->delete();
        DB::table('hr_payroll_run_people')->where('run_id', $run->id)->delete();
        foreach ($calc['people'] as $workerId => $person) {
            $personId = DB::table('hr_payroll_run_people')->insertGetId(['organisation_id' => $actor->organisation_id, 'run_id' => $run->id, 'worker_id' => $workerId,
                'profile_id' => $person['profileId'], 'mtd_encrypted' => array_key_exists($workerId, $mtd) ? Crypt::encryptString((string) $mtd[$workerId]) : null,
                'totals_encrypted' => Crypt::encryptString(json_encode($person['totals'], JSON_THROW_ON_ERROR)),
                'errors' => json_encode($person['errors'], JSON_THROW_ON_ERROR), 'warnings' => json_encode($person['warnings'], JSON_THROW_ON_ERROR)]);
            foreach ($person['lines'] as $i => $line) {
                DB::table('hr_payroll_lines')->insert(['organisation_id' => $actor->organisation_id, 'run_id' => $run->id, 'run_person_id' => $personId, 'position' => $i + 1,
                    'kind' => $line['kind'], 'section' => $line['section'], 'item_type_id' => $line['itemTypeId'], 'item_type_version' => $line['itemTypeVersion'],
                    'scheme' => $line['scheme'], 'source_kind' => $line['sourceKind'], 'source_id' => $line['sourceId'],
                    'amount_encrypted' => Crypt::encryptString((string) $line['amount']), 'explanation_encrypted' => Crypt::encryptString((string) $line['explanation'])]);
            }
        }
        $now = now()->utc();
        DB::table('hr_payroll_runs')->where('id', $run->id)->update(['fingerprint' => $calc['fingerprint'], 'prepared_at' => $now,
            'lock_version' => (int) $run->lock_version + 1, 'updated_at' => $now]);
        $this->action($actor, $run, 'prepared');

        return count($calc['people']);
    }

    /** @return array<int, int> MTD in sen per worker id, as entered on the draft */
    private function mtd(\stdClass $run): array
    {
        $out = [];
        foreach (DB::table('hr_payroll_run_people')->where('run_id', $run->id)->whereNotNull('mtd_encrypted')->get(['worker_id', 'mtd_encrypted']) as $p) {
            try {
                $value = Crypt::decryptString((string) $p->mtd_encrypted);
            } catch (DecryptException) {
                continue; // an unreadable MTD counts as not entered, so the run shows "MTD not entered" (Suresh, M7)
            }
            if (preg_match('/\A[0-9]{1,9}\z/', $value) === 1) {
                $out[(int) $p->worker_id] = (int) $value;
            }
        }

        return $out;
    }

    private function action(User $actor, \stdClass $run, string $action, ?string $reason = null): void
    {
        DB::table('hr_payroll_run_actions')->insert(['organisation_id' => $actor->organisation_id, 'run_id' => $run->id, 'action' => $action,
            'actor_user_id' => $actor->id, 'reason_code' => $reason, 'created_at' => now()->utc()]);
    }

    private function run(User $actor, string $runId): \stdClass
    {
        if (! Str::isUuid($runId)) {
            abort(404);
        }
        $run = DB::table('hr_payroll_runs')->where('organisation_id', $actor->organisation_id)->where('public_id', $runId)->lockForUpdate()->first();
        abort_if(! $run instanceof \stdClass, 404);

        return $run;
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}
