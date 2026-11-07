<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR-7c payslips (HR-PLAN 17.2). One encrypted snapshot per person, written inside the lock transaction from the
 * locked run's own lines and never changed: a later letterhead, name or bank change never reaches an issued payslip.
 * Each person opens only their own; preparers and approvers open any. Every open is recorded, with no amount.
 */
final class PayslipService
{
    public const SECTIONS = ['earnings' => 'Earnings', 'deduction' => 'Deductions', 'refund' => 'Refunds', 'employee' => 'Employee contributions',
        'mtd' => 'MTD (PCB)', 'employer' => 'Employer contributions (information)'];

    public function __construct(private RecordSupport $support, private BranchLetterheadAssembler $letterheads) {}

    /** Writes the run's payslips. Called inside the lock transaction, after the run became locked. Returns how many. */
    public function issue(User $actor, \stdClass $run): int
    {
        $people = DB::table('hr_payroll_run_people')->where('run_id', $run->id)->orderBy('id')->get();
        $workers = Worker::query()->with('user')->whereIn('id', $people->pluck('worker_id')->all())->get()->keyBy('id');
        $lines = DB::table('hr_payroll_lines')->where('run_id', $run->id)->orderBy('position')->get()->groupBy('run_person_id');
        $period = CarbonImmutable::parse((string) $run->month);
        $adjusts = $run->adjusts_run_id === null ? null : DB::table('hr_payroll_runs')->where('id', $run->adjusts_run_id)->value('month');
        $lockedAt = now()->utc();
        $count = 0;
        foreach ($people as $p) {
            $worker = $workers->get($p->worker_id);
            if (! $worker instanceof Worker || $worker->user_id === null) {
                continue; // a run never holds a locum (HR-7b), so this is only a guard
            }
            $bank = DB::table('hr_bank_details')->where('worker_id', $worker->id)->where('status', 'confirmed')->orderByDesc('id')->first(['bank_name_encrypted', 'account_last4']);
            $snapshot = [
                'version' => 1,
                'name' => $worker->name(),
                'kind' => (string) $run->kind,
                'period' => $period->format('F Y'),
                'periodValue' => $period->format('Y-m'),
                'adjusts' => $adjusts === null ? null : CarbonImmutable::parse((string) $adjusts)->format('F Y'),
                'group' => (string) $run->group_name,
                'payDay' => $run->group_pay_day === null ? null : (int) $run->group_pay_day,
                'letterhead' => $this->letterhead($worker, $period),
                'lines' => $lines->get($p->id, collect())->map(fn (\stdClass $l): array => ['kind' => (string) $l->kind, 'section' => (string) $l->section,
                    'amountSen' => (int) $this->number((string) $l->amount_encrypted), 'explanation' => $this->text((string) $l->explanation_encrypted)])->values()->all(),
                'totals' => $this->totals((string) $p->totals_encrypted),
                'bank' => $bank === null ? null : ['name' => $this->text((string) $bank->bank_name_encrypted), 'last4' => (string) $bank->account_last4],
                'lockedAt' => $lockedAt->toIso8601String(),
            ];
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            DB::table('hr_payslips')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'run_id' => $run->id,
                'worker_id' => $worker->id, 'user_id' => $worker->user_id, 'snapshot_encrypted' => Crypt::encryptString($json),
                'snapshot_hash' => hash('sha256', $json), 'created_at' => $lockedAt]);
            $count++;
        }

        return $count;
    }

    /** @return list<array<string, mixed>> the actor's own payslips, newest first, with no amount */
    public function mine(User $actor): array
    {
        if (! $actor->can(PayrollPermissions::PAYSLIPS_OWN)) {
            return [];
        }

        return array_values(DB::table('hr_payslips as s')->join('hr_payroll_runs as r', 'r.id', '=', 's.run_id')
            ->where('s.organisation_id', $actor->organisation_id)->where('s.user_id', $actor->id)
            ->orderByDesc('r.month')->orderByDesc('s.id')->limit(60)->get(['s.public_id', 'r.month', 'r.kind', 'r.group_name', 's.created_at'])
            ->map(fn (\stdClass $s): array => ['id' => (string) $s->public_id, 'period' => CarbonImmutable::parse((string) $s->month)->format('F Y'),
                'kind' => (string) $s->kind, 'group' => (string) $s->group_name,
                'issuedOn' => CarbonImmutable::parse((string) $s->created_at)->format('j M Y')])->all());
    }

    /**
     * Records the open, then returns the snapshot. Your own payslip, or any for a preparer or approver.
     *
     * @return array<string, mixed>
     */
    public function open(User $actor, string $payslipId): array
    {
        return DB::transaction(function () use ($actor, $payslipId): array {
            abort_if(! Str::isUuid($payslipId), 404);
            $payslip = DB::table('hr_payslips')->where('organisation_id', $actor->organisation_id)->where('public_id', $payslipId)->first();
            abort_if(! $payslip instanceof \stdClass, 404);
            $own = (int) $payslip->user_id === $actor->id && $actor->can(PayrollPermissions::PAYSLIPS_OWN);
            if (! $own && ! $actor->can(PayrollPermissions::PREPARE) && ! $actor->can(PayrollPermissions::APPROVE)) {
                throw new AuthorizationException('You may not open this payslip.');
            }
            DB::table('hr_payslip_opens')->insert(['organisation_id' => $actor->organisation_id, 'payslip_id' => $payslip->id,
                'opened_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->audit->record('hr.payslips.opened', null, ['payslip_id' => (int) $payslip->id, 'run_id' => (int) $payslip->run_id, 'own' => $own],
                $actor, null, $actor->organisation_id);
            $json = Crypt::decryptString((string) $payslip->snapshot_encrypted);
            abort_if(! hash_equals((string) $payslip->snapshot_hash, hash('sha256', $json)), 409);
            $snapshot = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

            return ['id' => (string) $payslip->public_id, 'own' => $own, ...(is_array($snapshot) ? $snapshot : [])];
        });
    }

    /**
     * The letterhead text of the worker's primary branch on the period's last day (else the organisation's first active
     * branch), frozen into the payslip. No logo in v1, so the snapshot holds text only.
     *
     * @return array<string, mixed>
     */
    private function letterhead(Worker $worker, CarbonImmutable $period): array
    {
        $day = $period->endOfMonth()->toDateString();
        $branchId = DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')
            ->where('p.user_id', $worker->user_id)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $day))->orderByDesc('a.valid_from')->value('a.branch_id');
        $branch = Branch::query()->whereKey($branchId)->first()
            ?? Branch::query()->where('organisation_id', $worker->organisation_id)->where('is_active', true)->orderBy('id')->first();
        if (! $branch instanceof Branch) {
            return ['clinicName' => '', 'legalName' => '', 'registration' => null, 'address' => [], 'contact' => []];
        }
        $l = $this->letterheads->assemble($branch, fn (string $id): string => '');
        $d = $l->details;
        $registration = $l->organisation['registration_number'] === null ? null
            : trim(($l->organisation['registration_type'] ?? '').' '.$l->organisation['registration_number']);

        return ['clinicName' => $d['clinic_name'], 'legalName' => $l->organisation['legal_name'], 'registration' => $registration,
            'address' => array_values(array_filter([$d['address_line_1'], $d['address_line_2'], trim($d['postcode'].' '.$d['city']), $d['state']],
                fn (?string $v): bool => $v !== null && trim($v) !== '')),
            'contact' => array_values(array_filter([$d['phone'] === null ? null : 'Tel: '.$d['phone'], $d['email']], fn (?string $v): bool => $v !== null && $v !== ''))];
    }

    /** @return array<string, int> */
    private function totals(string $encrypted): array
    {
        $totals = json_decode($this->text($encrypted), true) ?: [];
        $out = [];
        foreach (['earnings', 'deduction', 'refund', 'employee', 'employer', 'mtd', 'net'] as $k) {
            $out[$k] = (int) ($totals[$k] ?? 0);
        }

        return $out;
    }

    private function number(string $encrypted): string
    {
        $plain = $this->text($encrypted);

        return preg_match('/\A-?[0-9]{1,10}\z/', $plain) === 1 ? $plain : '0';
    }

    private function text(string $encrypted): string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return '';
        }
    }
}
