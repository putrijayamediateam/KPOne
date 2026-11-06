<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-7b page props: the Runs tab on /hr/payroll and one run at /hr/payroll/runs/{run}. Read only. Amounts reach these
 * pages, for payroll preparers and approvers only; internal ids stay on the server. Every step button carries the
 * service's own refusal.
 */
final class PayrollRunPageService
{
    public function __construct(private PayrollRunService $runs) {}

    /** @return array<string, mixed> */
    public function list(User $actor): array
    {
        $org = $actor->organisation_id;
        $runs = DB::table('hr_payroll_runs')->where('organisation_id', $org)->orderByDesc('month')->orderByDesc('id')->limit(60)->get();
        $people = DB::table('hr_payroll_run_people')->whereIn('run_id', $runs->pluck('id')->all())->get()->groupBy('run_id');
        $lineTypes = DB::table('hr_payroll_line_types')->where('organisation_id', $org)->orderByDesc('version')->first();
        $publicIds = DB::table('hr_pay_item_types')->where('organisation_id', $org)->pluck('public_id', 'id');
        $zone = $this->zone($org);
        $months = [];
        for ($m = CarbonImmutable::now($zone)->startOfMonth()->subMonths(2), $i = 0; $i < 4; $i++, $m = $m->addMonth()) {
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return [
            'runs' => $runs->map(function (\stdClass $r) use ($people): array {
                $group = $people->get($r->id, collect());
                $net = 0;
                foreach ($group as $p) {
                    $net += $this->totals($p)['net'];
                }

                return ['id' => (string) $r->public_id, 'kind' => (string) $r->kind, 'month' => CarbonImmutable::parse((string) $r->month)->format('F Y'),
                    'monthValue' => CarbonImmutable::parse((string) $r->month)->format('Y-m'), 'group' => (string) $r->group_name, 'status' => (string) $r->status,
                    'people' => $group->count(), 'netSen' => $net,
                    'errors' => $group->sum(fn (\stdClass $p): int => count(json_decode((string) $p->errors, true) ?: [])),
                    'lockedAt' => $r->locked_at === null ? null : CarbonImmutable::parse((string) $r->locked_at)->toIso8601String()];
            })->values()->all(),
            'months' => $months,
            'lineTypes' => ['version' => $lineTypes === null ? 0 : (int) $lineTypes->version,
                'overtime' => $lineTypes?->overtime_type_id === null ? null : (string) $publicIds->get($lineTypes->overtime_type_id),
                'unpaidLeave' => $lineTypes?->unpaid_leave_type_id === null ? null : (string) $publicIds->get($lineTypes->unpaid_leave_type_id),
                'claim' => $lineTypes?->claim_type_id === null ? null : (string) $publicIds->get($lineTypes->claim_type_id)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $actor, string $runId): array
    {
        $org = $actor->organisation_id;
        $run = DB::table('hr_payroll_runs')->where('organisation_id', $org)->where('public_id', $runId)->first();
        abort_if(! $run instanceof \stdClass, 404);
        $people = DB::table('hr_payroll_run_people')->where('run_id', $run->id)->get();
        $workers = Worker::query()->with('user')->whereIn('id', $people->pluck('worker_id')->all())->get()->keyBy('id');
        $lines = DB::table('hr_payroll_lines')->where('run_id', $run->id)->orderBy('position')->get()->groupBy('run_person_id');
        $manual = DB::table('hr_payroll_manual_lines')->where('run_id', $run->id)->orderBy('id')->get()->groupBy('worker_id');
        $names = DB::table('users')->whereIn('id', array_filter([$run->prepared_by_user_id, $run->sent_by_user_id, $run->approved_by_user_id, $run->locked_by_user_id]))
            ->pluck('name', 'id');
        $rows = $people->map(function (\stdClass $p) use ($workers, $lines, $manual): array {
            $worker = $workers->get($p->worker_id);
            $mtd = $p->mtd_encrypted === null ? null : (int) $this->decrypt((string) $p->mtd_encrypted);

            return ['id' => (string) ($worker->public_id ?? ''), 'name' => $worker instanceof Worker ? $worker->name() : 'Staff member', 'totals' => $this->totals($p), 'mtdSen' => $mtd,
                'needsMtd' => in_array('mtd_missing', json_decode((string) $p->errors, true) ?: [], true) || $mtd !== null,
                'errors' => array_map(fn (string $c): string => $this->errorWords($c), json_decode((string) $p->errors, true) ?: []),
                'warnings' => array_map(fn (string $c): string => PayrollRunCalculator::WARNINGS[$c] ?? $c, json_decode((string) $p->warnings, true) ?: []),
                'lines' => $lines->get($p->id, collect())->map(fn (\stdClass $l): array => ['kind' => (string) $l->kind, 'section' => (string) $l->section,
                    'amountSen' => (int) $this->decrypt((string) $l->amount_encrypted), 'explanation' => $this->decryptText((string) $l->explanation_encrypted)])->values()->all(),
                'manual' => $manual->get($p->worker_id, collect())->map(fn (\stdClass $m): array => ['id' => (string) $m->public_id])->values()->all()];
        })->sortBy(fn (array $r): string => mb_strtolower($r['name']))->values();
        $total = ['earnings' => 0, 'deduction' => 0, 'refund' => 0, 'employee' => 0, 'employer' => 0, 'mtd' => 0, 'net' => 0];
        foreach ($rows as $r) {
            foreach ($total as $k => $v) {
                $total[$k] = $v + $r['totals'][$k];
            }
        }
        $errorCount = $rows->sum(fn (array $r): int => count($r['errors']));
        $draft = $run->status === 'draft';
        $sendRefusal = ! $draft ? null : ($rows->isEmpty() ? 'Nobody is in this run yet.' : ($errorCount > 0 ? "Fix {$errorCount} ".($errorCount === 1 ? 'error' : 'errors').' first.' : null));
        $types = DB::table('hr_pay_item_types as t')->join('hr_pay_item_type_versions as v', function ($join): void {
            $join->on('v.item_type_id', '=', 't.id')->on('v.version', '=', 't.current_version');
        })->where('t.organisation_id', $org)->where('v.is_active', true)->orderBy('v.name')->get(['t.public_id', 'v.name', 'v.kind']);
        $steps = [];
        foreach (['approve', 'return', 'lock'] as $step) {
            $steps[$step] = ['refusal' => $actor->can(PayrollPermissions::APPROVE) ? $this->runs->deciderRefusal($actor, $run, $step) : 'The Director approves and locks payroll runs.'];
        }

        return [
            'id' => (string) $run->public_id, 'kind' => (string) $run->kind, 'status' => (string) $run->status,
            'month' => CarbonImmutable::parse((string) $run->month)->format('F Y'), 'group' => (string) $run->group_name,
            'payDay' => $run->group_pay_day === null ? null : (int) $run->group_pay_day, 'lockVersion' => (int) $run->lock_version,
            'preparedBy' => (string) ($names->get($run->prepared_by_user_id) ?? 'Staff member'),
            'sentBy' => $run->sent_by_user_id === null ? null : (string) ($names->get($run->sent_by_user_id) ?? 'Staff member'),
            'approvedBy' => $run->approved_by_user_id === null ? null : (string) ($names->get($run->approved_by_user_id) ?? 'Staff member'),
            'lockedAt' => $run->locked_at === null ? null : CarbonImmutable::parse((string) $run->locked_at)->toIso8601String(),
            'people' => $rows->all(), 'total' => $total, 'errorCount' => $errorCount,
            'canEdit' => $draft, 'sendRefusal' => $sendRefusal, 'steps' => $steps,
            'options' => ['itemTypes' => $types->map(fn (\stdClass $t): array => ['value' => (string) $t->public_id, 'label' => $t->name.' ('.(PayrollSetupService::KINDS[$t->kind] ?? $t->kind).')'])->all(),
                'manualReasons' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(PayrollRunService::MANUAL_REASONS), PayrollRunService::MANUAL_REASONS),
                'returnReasons' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(PayrollRunService::RETURN_REASONS), PayrollRunService::RETURN_REASONS)],
        ];
    }

    /** @return array<string, int> */
    private function totals(\stdClass $person): array
    {
        $plain = $person->totals_encrypted === null ? null : $this->decryptText((string) $person->totals_encrypted);
        $totals = $plain === '' || $plain === null ? [] : (json_decode($plain, true) ?: []);
        $out = [];
        foreach (['earnings', 'deduction', 'refund', 'employee', 'employer', 'mtd', 'net'] as $k) {
            $out[$k] = (int) ($totals[$k] ?? 0);
        }

        return $out;
    }

    private function errorWords(string $code): string
    {
        if (isset(PayrollRunCalculator::ERRORS[$code])) {
            return PayrollRunCalculator::ERRORS[$code];
        }
        if (preg_match('/\A(no_table|two_tables|no_band)_(epf|socso|eis|hrd)\z/', $code, $m) === 1) {
            $scheme = PayrollRunCalculator::SCHEME_LABELS[$m[2]];

            return match ($m[1]) {
                'no_table' => "No checked {$scheme} table for this person's category",
                'two_tables' => "More than one checked {$scheme} table in force",
                default => "No {$scheme} band for this wage",
            };
        }

        return $code;
    }

    private function decrypt(string $value): string
    {
        $plain = $this->decryptText($value);

        return preg_match('/\A-?[0-9]{1,10}\z/', $plain) === 1 ? $plain : '0';
    }

    private function decryptText(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}
