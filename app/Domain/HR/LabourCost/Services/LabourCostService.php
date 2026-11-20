<?php

namespace App\Domain\HR\LabourCost\Services;

use App\Domain\HR\LabourCost\LabourCostPermissions;
use App\Domain\HR\Payroll\Services\PayrollMoney;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-18 labour cost (owner, 18 and 20 Nov 2026). Read only. One row per branch for one month, from approved or locked
 * pay runs (the HR-9b rule) and from locum months Finance recorded as paid (HR-15g):
 * - a staff member counts at their primary branch on the month's last day; a locum's pay at the branch of each paid shift;
 * - a branch with fewer than 3 people is not shown, and when exactly one branch is hidden the next smallest is hidden too,
 *   so the total less the shown branches cannot reveal it; the clinic total shows only with at least 3 people;
 * - no person, role or pay group ever leaves this class; amounts are decrypted here and only summed.
 * To check against current law before real use. Synthetic data only.
 */
final class LabourCostService
{
    public const MIN_PEOPLE = 3;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        if (! $actor->can(LabourCostPermissions::VIEW)) {
            throw new AuthorizationException;
        }
        $organisationId = (int) $actor->organisation_id;
        $zone = (string) (DB::table('branches')->where('organisation_id', $organisationId)->orderBy('id')->value('timezone') ?? 'Asia/Kuala_Lumpur');
        $thisMonth = CarbonImmutable::now($zone)->startOfMonth();
        $months = collect(range(0, 11))->map(fn (int $i): CarbonImmutable => $thisMonth->subMonths($i));
        $requested = is_string($query['month'] ?? null) ? $query['month'] : '';
        $month = $months->first(fn (CarbonImmutable $m): bool => $m->format('Y-m') === $requested) ?? $thisMonth;

        return [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $months->map(fn (CarbonImmutable $m): array => ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')])->values()->all(),
            ...$this->forMonth($organisationId, $month),
        ];
    }

    /** @return array<string, mixed> */
    public function forMonth(int $organisationId, CarbonImmutable $month): array
    {
        $first = $month->toDateString();
        $last = $month->endOfMonth()->toDateString();
        $branches = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $byName = $branches->pluck('id', 'name')->map(fn ($id): int => (int) $id)->all();
        /** @var array<int, array{name: string, people: array<int, true>, pay: int, employer: int, locum: int}> $cells */
        $cells = [];
        foreach ($branches as $branch) {
            $cells[(int) $branch->id] = ['name' => (string) $branch->name, 'people' => [], 'pay' => 0, 'employer' => 0, 'locum' => 0];
        }
        $unreadable = 0;
        $unassigned = [];
        $outside = ['pay' => 0, 'employer' => 0, 'locum' => 0, 'people' => []];

        // Pay runs: approved or locked only, as the dashboard staff cost.
        $runs = DB::table('hr_payroll_runs')->where('organisation_id', $organisationId)->where('month', $first)->get(['id', 'status']);
        $counted = $runs->whereIn('status', ['approved', 'locked'])->pluck('id')->all();
        if ($counted !== []) {
            $people = DB::table('hr_payroll_run_people as p')->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
                ->where('p.organisation_id', $organisationId)->whereIn('p.run_id', $counted)->get(['p.id', 'p.worker_id', 'p.totals_encrypted', 'w.user_id']);
            $primary = $this->primaryBranches(array_values($people->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all()), $last);
            $unpaid = [];
            foreach (DB::table('hr_payroll_lines')->where('organisation_id', $organisationId)->whereIn('run_id', $counted)->where('kind', 'unpaid_leave')
                ->get(['run_person_id', 'amount_encrypted']) as $line) {
                $plain = $this->decrypt((string) $line->amount_encrypted);
                if ($plain === null || preg_match('/\A-?[0-9]{1,10}\z/', $plain) !== 1) {
                    $unreadable++;

                    continue;
                }
                $unpaid[(int) $line->run_person_id] = ($unpaid[(int) $line->run_person_id] ?? 0) + (int) $plain;
            }
            foreach ($people as $row) {
                $plain = is_string($row->totals_encrypted) ? $this->decrypt($row->totals_encrypted) : null;
                $totals = $plain === null ? null : json_decode($plain, true);
                if (! is_array($totals)) {
                    $unreadable++;

                    continue;
                }
                $pay = (int) ($totals['earnings'] ?? 0) - ($unpaid[(int) $row->id] ?? 0);
                $employer = (int) ($totals['employer'] ?? 0);
                $branchId = $row->user_id === null ? null : ($primary[(int) $row->user_id] ?? null);
                if ($branchId === null || ! isset($cells[$branchId])) {
                    $unassigned[(int) $row->worker_id] = true;
                    $outside['pay'] += $pay;
                    $outside['employer'] += $employer;
                    $outside['people'][(int) $row->worker_id] = true;

                    continue;
                }
                $cells[$branchId]['people'][(int) $row->worker_id] = true;
                $cells[$branchId]['pay'] += $pay;
                $cells[$branchId]['employer'] += $employer;
            }
        }

        // Locum months Finance recorded as paid: each paid shift at its own branch; corrections have no branch.
        $corrections = 0;
        foreach (DB::table('hr_locum_pay_months')->where('organisation_id', $organisationId)->where('month', $first)->get(['worker_id', 'statement_encrypted']) as $paid) {
            $plain = $this->decrypt((string) $paid->statement_encrypted);
            $statement = $plain === null ? null : json_decode($plain, true);
            if (! is_array($statement)) {
                $unreadable++;

                continue;
            }
            foreach (is_array($statement['lines'] ?? null) ? $statement['lines'] : [] as $line) {
                $amount = is_array($line) ? (int) ($line['amount'] ?? 0) : 0;
                if ($amount === 0) {
                    continue;
                }
                $branchId = $byName[(string) ($line['branch'] ?? '')] ?? null;
                if ($branchId === null || ! isset($cells[$branchId])) {
                    $outside['locum'] += $amount;
                    $outside['people'][(int) $paid->worker_id] = true;

                    continue;
                }
                $cells[$branchId]['people'][(int) $paid->worker_id] = true;
                $cells[$branchId]['locum'] += $amount;
            }
            foreach (is_array($statement['corrections'] ?? null) ? $statement['corrections'] : [] as $correction) {
                $corrections += is_array($correction) ? (int) ($correction['amount'] ?? 0) : 0;
            }
        }

        // The owner's rule: fewer than 3 people is not shown; one hidden branch hides the next smallest too.
        $hidden = [];
        foreach ($cells as $id => $cell) {
            $n = count($cell['people']);
            if ($n > 0 && $n < self::MIN_PEOPLE) {
                $hidden[$id] = 'few';
            }
        }
        if (count($hidden) === 1) {
            $next = collect($cells)->filter(fn (array $c, int $id): bool => ! isset($hidden[$id]) && count($c['people']) > 0)
                ->sortBy([fn (array $a, array $b): int => count($a['people']) <=> count($b['people']), fn (array $a, array $b): int => strcmp($a['name'], $b['name'])])
                ->keys()->first();
            if ($next !== null) {
                $hidden[(int) $next] = 'protect';
            }
        }

        $rows = [];
        $total = ['people' => [], 'pay' => 0, 'employer' => 0, 'locum' => 0];
        foreach ($cells as $id => $cell) {
            $sum = $cell['pay'] + $cell['employer'] + $cell['locum'];
            $total['people'] += $cell['people'];
            $total['pay'] += $cell['pay'];
            $total['employer'] += $cell['employer'];
            $total['locum'] += $cell['locum'];
            $state = $hidden[$id] ?? (count($cell['people']) === 0 ? 'none' : 'shown');
            $rows[] = ['branch' => $cell['name'], 'state' => $state] + ($state === 'shown' ? [
                'people' => count($cell['people']),
                'pay' => PayrollMoney::rm($cell['pay']), 'employer' => PayrollMoney::rm($cell['employer']),
                'locum' => PayrollMoney::rm($cell['locum']), 'total' => PayrollMoney::rm($sum),
            ] : []);
        }
        // People outside every branch (no primary branch, or a locum shift at a branch now inactive) still count in the total.
        $total['people'] += $outside['people'];
        $total['pay'] += $outside['pay'];
        $total['employer'] += $outside['employer'];
        $total['locum'] += $outside['locum'] + $corrections;
        $count = count($total['people']);
        $grand = $total['pay'] + $total['employer'] + $total['locum'];

        return [
            'rows' => $rows,
            'total' => $count >= self::MIN_PEOPLE ? [
                'people' => $count, 'pay' => PayrollMoney::rm($total['pay']), 'employer' => PayrollMoney::rm($total['employer']),
                'locum' => PayrollMoney::rm($total['locum']), 'total' => PayrollMoney::rm($grand),
            ] : null,
            'totalHidden' => $count > 0 && $count < self::MIN_PEOPLE,
            'runs' => count($counted),
            'waiting' => $runs->count() - count($counted),
            'unassigned' => count($unassigned),
            'locumCorrections' => $corrections !== 0 && $count >= self::MIN_PEOPLE ? PayrollMoney::rm($corrections) : null,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * Each user's primary branch on the given date (branch assignments are effective-dated).
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user id => branch id
     */
    private function primaryBranches(array $userIds, string $date): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')
            ->whereIn('p.user_id', $userIds)->where('a.is_primary', true)->where('a.valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhere('a.valid_until', '>=', $date))
            ->orderBy('a.valid_from')->get(['p.user_id', 'a.branch_id'])
            ->mapWithKeys(fn (object $r): array => [(int) $r->user_id => (int) $r->branch_id])->all();
    }

    private function decrypt(string $encrypted): ?string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
