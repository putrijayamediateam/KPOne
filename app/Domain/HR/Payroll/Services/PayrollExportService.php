<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Shared\Services\ReportCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-7c exports (HR-PLAN 17.3; the Director only in v1). From a locked run only: the bank file (generic CSV) and the
 * accountant summary CSV, each audited before its bytes are returned and never stored, and the statutory summary on
 * screen. Amounts and account numbers never reach audit.
 */
final class PayrollExportService
{
    public function __construct(private RecordSupport $support) {}

    /** @return list<array<string, mixed>> the locked runs, newest first, with their statutory summary */
    public function runs(User $actor): array
    {
        if (! $actor->can(PayrollPermissions::EXPORT)) {
            return [];
        }
        $runs = DB::table('hr_payroll_runs')->where('organisation_id', $actor->organisation_id)->where('status', 'locked')
            ->orderByDesc('month')->orderByDesc('id')->limit(24)->get();
        $out = [];
        foreach ($runs as $run) {
            $people = $this->people($run);
            $missing = $people->filter(fn (array $p): bool => $p['bank'] === null && $p['net'] > 0)->pluck('name')->sort()->values()->all();
            $out[] = ['id' => (string) $run->public_id, 'kind' => (string) $run->kind, 'month' => CarbonImmutable::parse((string) $run->month)->format('F Y'),
                'group' => (string) $run->group_name, 'people' => $people->count(), 'netSen' => $people->sum('net'),
                'lockedAt' => CarbonImmutable::parse((string) $run->locked_at)->toIso8601String(),
                'missingBank' => $missing, 'statutory' => $this->statutory($run),
                'exports' => DB::table('hr_payroll_exports')->where('run_id', $run->id)->count()];
        }

        return $out;
    }

    /**
     * The bank file pays the accounts frozen at locking. Anyone who had no confirmed bank details then is left out and
     * named on the Exports tab, to be paid outside the file; an account that cannot be read refuses the whole file.
     *
     * @return array{name: string, csv: string}
     */
    public function bank(User $actor, string $runId): array
    {
        return DB::transaction(function () use ($actor, $runId): array {
            [$actor, $run] = $this->lockedRun($actor, $runId);
            $people = $this->people($run, true)->filter(fn (array $p): bool => $p['net'] > 0 && $p['bank'] !== null)->values();
            if ($people->isEmpty()) {
                throw ValidationException::withMessages(['export' => 'Nobody in this run had confirmed bank details when it was locked, so there is no bank file.']);
            }
            $unreadable = $people->filter(fn (array $p): bool => preg_match('/\A\d{5,20}\z/', (string) $p['bank']['account']) !== 1)->pluck('name')->sort()->values()->all();
            if ($unreadable !== []) {
                throw ValidationException::withMessages(['export' => 'The bank account for '.implode(', ', $unreadable).' could not be read. Nothing was downloaded.']);
            }
            $period = CarbonImmutable::parse((string) $run->month)->format('F Y');
            $reference = ($run->kind === 'adjustment' ? 'Salary adjustment ' : 'Salary ').$period;
            $rows = array_values($people->map(fn (array $p): array => [$p['name'], (string) $p['bank']['holder'], (string) $p['bank']['name'], (string) $p['bank']['account'],
                ReportCsv::money($p['net']), $reference])->all());
            $csv = ReportCsv::encode(['Name', 'Account holder', 'Bank', 'Account number', 'Amount (MYR)', 'Reference'], $rows);
            $this->record($actor, $run, 'bank', 'generic_csv', count($rows));

            return ['name' => 'payroll-bank-'.CarbonImmutable::parse((string) $run->month)->format('Y-m').($run->kind === 'adjustment' ? '-adjustment' : '').'.csv', 'csv' => $csv];
        });
    }

    /** @return array{name: string, csv: string} */
    public function accountant(User $actor, string $runId): array
    {
        return DB::transaction(function () use ($actor, $runId): array {
            [$actor, $run] = $this->lockedRun($actor, $runId);
            $people = DB::table('hr_payroll_run_people')->where('run_id', $run->id)->get()->keyBy('id');
            $names = Worker::query()->with('user')->whereIn('id', $people->pluck('worker_id')->all())->get()->mapWithKeys(fn (Worker $w): array => [$w->id => $w->name()]);
            $typeNames = $this->typeNames($run);
            $sums = [];
            foreach (DB::table('hr_payroll_lines')->where('run_id', $run->id)->orderBy('run_person_id')->orderBy('position')->get() as $l) {
                $person = $people->get($l->run_person_id);
                $name = (string) ($names[$person->worker_id ?? 0] ?? 'Staff member');
                $item = $l->scheme !== null ? PayrollRunCalculator::SCHEME_LABELS[$l->scheme].' '.$l->section
                    : ($l->kind === 'basic' ? 'Basic salary' : ($l->kind === 'hourly' ? 'Worked hours' : ($l->kind === 'mtd' ? 'MTD (PCB)'
                        : (string) ($typeNames[$l->item_type_id.':'.$l->item_type_version] ?? 'Other'))));
                // Grouped by the worker, never by the name, so two people who share a name stay apart (Suresh, S2).
                $key = str_pad((string) ($person->worker_id ?? 0), 12, '0', STR_PAD_LEFT)."\0".$name."\0".$l->section."\0".$item;
                $sums[$key] = ($sums[$key] ?? 0) + (int) $this->number((string) $l->amount_encrypted);
            }
            ksort($sums, SORT_STRING);
            $rows = [];
            foreach ($sums as $key => $sen) {
                [, $name, $section, $item] = explode("\0", $key);
                $rows[] = [$name, PayslipService::SECTIONS[$section] ?? $section, $item, ReportCsv::money($sen)];
            }
            $period = CarbonImmutable::parse((string) $run->month)->format('F Y');
            $csv = ReportCsv::encode(['Name', 'Section', 'Item', 'Amount (MYR)'], $rows, [
                ['Payroll run', (string) $run->group_name.' · '.$period.($run->kind === 'adjustment' ? ' (adjustment)' : '')],
                ['Note', 'To confirm with the clinic\'s accountant before real use. Synthetic data only.']]);
            $this->record($actor, $run, 'accountant', 'summary_csv', count($rows));

            return ['name' => 'payroll-accountant-'.CarbonImmutable::parse((string) $run->month)->format('Y-m').($run->kind === 'adjustment' ? '-adjustment' : '').'.csv', 'csv' => $csv];
        });
    }

    /**
     * Totals per scheme for the portals, typed in by hand.
     *
     * @return list<array{scheme: string, people: int, employeeSen: int, employerSen: int}>
     */
    private function statutory(\stdClass $run): array
    {
        $out = [];
        $lines = DB::table('hr_payroll_lines')->where('run_id', $run->id)->where(fn ($q) => $q->whereNotNull('scheme')->orWhere('kind', 'mtd'))
            ->get(['run_person_id', 'scheme', 'kind', 'section', 'amount_encrypted']);
        foreach ([...array_keys(PayrollRunCalculator::SCHEME_LABELS), 'mtd'] as $scheme) {
            $mine = $lines->filter(fn (\stdClass $l): bool => $scheme === 'mtd' ? $l->kind === 'mtd' : $l->scheme === $scheme);
            if ($mine->isEmpty()) {
                continue;
            }
            $sum = fn (string $section): int => (int) $mine->filter(fn (\stdClass $l): bool => $l->section === $section)
                ->sum(fn (\stdClass $l): int => (int) $this->number((string) $l->amount_encrypted));
            $out[] = ['scheme' => $scheme === 'mtd' ? 'MTD (PCB)' : PayrollRunCalculator::SCHEME_LABELS[$scheme], 'people' => $mine->pluck('run_person_id')->unique()->count(),
                'employeeSen' => $scheme === 'mtd' ? $sum('mtd') : $sum('employee'), 'employerSen' => $scheme === 'mtd' ? 0 : $sum('employer')];
        }

        return $out;
    }

    /**
     * Each person's name, net pay and the bank details frozen on their payslip at locking (the account number only when
     * $account), so the file always pays the account the payslip shows (Suresh, S1).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function people(\stdClass $run, bool $account = false): Collection
    {
        $people = DB::table('hr_payroll_run_people')->where('run_id', $run->id)->get();
        $workers = Worker::query()->with('user')->whereIn('id', $people->pluck('worker_id')->all())->get()->keyBy('id');
        $frozen = DB::table('hr_payslips')->where('run_id', $run->id)->pluck('bank_detail_id', 'worker_id');
        $banks = DB::table('hr_bank_details')->whereIn('id', $frozen->filter()->values()->all())->get()->keyBy('id');

        /** @var Collection<int, array<string, mixed>> $out */
        $out = $people->map(function (\stdClass $p) use ($workers, $banks, $frozen, $account): array {
            $totals = json_decode($this->text((string) $p->totals_encrypted), true) ?: [];
            $bank = $banks->get($frozen->get($p->worker_id));
            $worker = $workers->get($p->worker_id);

            return ['workerId' => (int) $p->worker_id, 'name' => $worker instanceof Worker ? $worker->name() : 'Staff member', 'net' => (int) ($totals['net'] ?? 0),
                'bank' => $bank === null ? null : ['name' => $this->text((string) $bank->bank_name_encrypted), 'holder' => $this->text((string) $bank->holder_encrypted),
                    'account' => $account ? $this->text((string) $bank->account_encrypted) : '']];
        })->sortBy(fn (array $p): string => mb_strtolower($p['name']))->values();

        return $out;
    }

    /** @return array<string, string> "id:version" => name */
    private function typeNames(\stdClass $run): array
    {
        return DB::table('hr_pay_item_type_versions as v')->join('hr_pay_item_types as t', 't.id', '=', 'v.item_type_id')
            ->where('t.organisation_id', $run->organisation_id)->get(['v.item_type_id', 'v.version', 'v.name'])
            ->mapWithKeys(fn (\stdClass $v): array => [$v->item_type_id.':'.$v->version => (string) $v->name])->all();
    }

    /** @return array{0: User, 1: \stdClass} */
    private function lockedRun(User $actor, string $runId): array
    {
        $actor = $this->support->lock($actor, PayrollPermissions::EXPORT);
        abort_if(! Str::isUuid($runId), 404);
        $run = DB::table('hr_payroll_runs')->where('organisation_id', $actor->organisation_id)->where('public_id', $runId)->lockForUpdate()->first();
        abort_if(! $run instanceof \stdClass, 404);
        if ($run->status !== 'locked') {
            throw ValidationException::withMessages(['export' => 'Only a locked run is exported.']);
        }

        return [$actor, $run];
    }

    /** The audit and the export row are written before the file is returned; the file itself is never stored. */
    private function record(User $actor, \stdClass $run, string $kind, string $layout, int $rows): void
    {
        $id = DB::table('hr_payroll_exports')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'run_id' => $run->id,
            'kind' => $kind, 'layout' => $layout, 'row_count' => $rows, 'exported_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
        $this->support->audit->record('hr.payroll.exported', null, ['run_id' => (int) $run->id, 'export_id' => $id, 'kind' => $kind, 'layout' => $layout, 'rows' => $rows],
            $actor, null, $actor->organisation_id);
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
