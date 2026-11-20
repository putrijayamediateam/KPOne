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
        /** @var array<int, array{name: string, staff: array<int, true>, locums: array<int, true>, pay: int, employer: int, locum: int}> $cells */
        $cells = [];
        foreach ($branches as $branch) {
            $cells[(int) $branch->id] = ['name' => (string) $branch->name, 'staff' => [], 'locums' => [], 'pay' => 0, 'employer' => 0, 'locum' => 0];
        }
        $unreadable = 0;
        $unassigned = [];
        $outside = ['pay' => 0, 'employer' => 0, 'locum' => 0, 'staff' => [], 'locums' => []];

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
                    $outside['staff'][(int) $row->worker_id] = true;

                    continue;
                }
                $cells[$branchId]['staff'][(int) $row->worker_id] = true;
                $cells[$branchId]['pay'] += $pay;
                $cells[$branchId]['employer'] += $employer;
            }
        }

        // Locum months Finance recorded as paid: each paid shift at its own branch; corrections have no branch.
        $corrections = 0;
        $correctionPeople = [];
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
                    $outside['locums'][(int) $paid->worker_id] = true;

                    continue;
                }
                $cells[$branchId]['locums'][(int) $paid->worker_id] = true;
                $cells[$branchId]['locum'] += $amount;
            }
            foreach (is_array($statement['corrections'] ?? null) ? $statement['corrections'] : [] as $correction) {
                $amount = is_array($correction) ? (int) ($correction['amount'] ?? 0) : 0;
                $corrections += $amount;
                if ($amount !== 0) {
                    $correctionPeople[(int) $paid->worker_id] = true;
                }
            }
        }

        // The owner's rule, applied to every figure on the page (owner, 18 and 20 Nov 2026; Suresh, HR-18 M1):
        // - a branch with fewer than 3 people is not shown at all;
        // - in a shown branch, the staff figures (pay, employer contributions) need 0 or at least 3 staff, and the locum
        //   figure 0 or at least 3 locums; the branch total shows only when both do;
        // - the clinic total less the shown branches must not reveal fewer than 3 people in any column: while the not-shown
        //   group of a column has 1 or 2 people, that column is also withheld for the next smallest branch; with nothing left
        //   to withhold, the clinic total of that column (and the grand total) is withheld.
        $few = [];
        $staffShown = [];
        $locumShown = [];
        $staffGroup = $outside['staff'];
        $locumGroup = $outside['locums'] + $correctionPeople;
        foreach ($cells as $id => $cell) {
            $people = count($cell['staff'] + $cell['locums']);
            if ($people > 0 && $people < self::MIN_PEOPLE) {
                $few[$id] = true;
                $staffGroup += $cell['staff'];
                $locumGroup += $cell['locums'];

                continue;
            }
            $staffShown[$id] = $this->safe(count($cell['staff']));
            $locumShown[$id] = $this->safe(count($cell['locums']));
            if (! $staffShown[$id]) {
                $staffGroup += $cell['staff'];
            }
            if (! $locumShown[$id]) {
                $locumGroup += $cell['locums'];
            }
        }
        $staffBlocked = $this->protect($cells, $staffShown, $staffGroup, 'staff');
        $locumBlocked = $this->protect($cells, $locumShown, $locumGroup, 'locums');

        $rows = [];
        $someHidden = false;
        $total = ['people' => $outside['staff'] + $outside['locums'] + $correctionPeople, 'pay' => $outside['pay'], 'employer' => $outside['employer'],
            'locum' => $outside['locum'] + $corrections];
        foreach ($cells as $id => $cell) {
            $people = $cell['staff'] + $cell['locums'];
            $total['people'] += $people;
            $total['pay'] += $cell['pay'];
            $total['employer'] += $cell['employer'];
            $total['locum'] += $cell['locum'];
            $state = isset($few[$id]) ? 'few' : ($people === [] ? 'none' : 'shown');
            if ($state !== 'shown') {
                $rows[] = ['branch' => $cell['name'], 'state' => $state];

                continue;
            }
            $staff = $staffShown[$id];
            $locum = $locumShown[$id];
            $someHidden = $someHidden || ! $staff || ! $locum;
            $rows[] = ['branch' => $cell['name'], 'state' => 'shown', 'people' => count($people),
                'pay' => $staff ? PayrollMoney::rm($cell['pay']) : null, 'employer' => $staff ? PayrollMoney::rm($cell['employer']) : null,
                'locum' => $locum ? PayrollMoney::rm($cell['locum']) : null,
                'total' => $staff && $locum ? PayrollMoney::rm($cell['pay'] + $cell['employer'] + $cell['locum']) : null];
        }
        $count = count($total['people']);
        $groupsSafe = $this->safe(count($staffGroup)) && $this->safe(count($locumGroup));

        return [
            'rows' => $rows,
            'total' => $count >= self::MIN_PEOPLE ? [
                'people' => $count,
                'pay' => $staffBlocked ? null : PayrollMoney::rm($total['pay']),
                'employer' => $staffBlocked ? null : PayrollMoney::rm($total['employer']),
                'locum' => $locumBlocked ? null : PayrollMoney::rm($total['locum']),
                'total' => $staffBlocked || $locumBlocked ? null : PayrollMoney::rm($total['pay'] + $total['employer'] + $total['locum']),
            ] : null,
            'totalHidden' => $count > 0 && $count < self::MIN_PEOPLE,
            'someHidden' => $someHidden || $staffBlocked || $locumBlocked,
            'runs' => count($counted),
            'waiting' => $runs->count() - count($counted),
            // A count only, never an amount; shown when no column's not-shown group has 1 or 2 people.
            'unassigned' => $groupsSafe ? count($unassigned) : 0,
            // The corrections figure is a sum of its own, so it needs at least 3 locums with a correction.
            'locumCorrections' => $corrections !== 0 && count($correctionPeople) >= self::MIN_PEOPLE && ! $locumBlocked ? PayrollMoney::rm($corrections) : null,
            'unreadable' => $unreadable,
        ];
    }

    private function safe(int $people): bool
    {
        return $people === 0 || $people >= self::MIN_PEOPLE;
    }

    /**
     * Withholds one column for the next smallest branch while the column's not-shown group has 1 or 2 people. Returns
     * true when nothing is left to withhold, so the clinic total of that column must be withheld too.
     *
     * @param  array<int, array{name: string, staff: array<int, true>, locums: array<int, true>, pay: int, employer: int, locum: int}>  $cells
     * @param  array<int, bool>  $shown
     * @param  array<int, true>  $group
     * @param  'staff'|'locums'  $set
     */
    private function protect(array $cells, array &$shown, array &$group, string $set): bool
    {
        while (! $this->safe(count($group))) {
            $next = collect($shown)->filter(fn (bool $isShown, int $id): bool => $isShown && count($cells[$id][$set]) > 0)->keys()
                ->sortBy([fn (int $a, int $b): int => count($cells[$a][$set]) <=> count($cells[$b][$set]), fn (int $a, int $b): int => strcmp($cells[$a]['name'], $cells[$b]['name'])])
                ->first();
            if ($next === null) {
                return true;
            }
            $shown[(int) $next] = false;
            $group += $cells[(int) $next][$set];
        }

        return false;
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
            ->orderBy('a.valid_from')->orderBy('a.id')->get(['p.user_id', 'a.branch_id'])
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
