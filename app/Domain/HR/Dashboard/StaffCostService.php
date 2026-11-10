<?php

namespace App\Domain\HR\Dashboard;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Payroll\Services\PayrollMoney;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-9b staff cost on the HR dashboard (docs/teams/hr/HR-9-DESIGN.md section 3). Read only: it sums the totals of
 * approved or locked pay runs for one month into a single clinic-wide figure. It never returns a person, a branch or a
 * pay group, and it returns nothing at all to anyone without the approve-payroll permission (the Director).
 * To confirm with the clinic's accountant before real use. Synthetic data only.
 */
final class StaffCostService
{
    /**
     * HR-9b: one clinic-wide figure for the month, from approved or locked payroll runs only (a draft or a run waiting
     * for approval never counts). Staff cost = gross earnings plus the employer's statutory contributions, in sen.
     * No person, branch or pay group split leaves this method; the totals are decrypted here and only summed.
     *
     * @return array<string, int|string>|null null unless the viewer holds the approve-payroll permission
     */
    public function forMonth(User $actor, string $month): ?array
    {
        if (! $actor->can(PayrollPermissions::APPROVE)) {
            return null;
        }
        $organisationId = $actor->organisation_id;
        $first = $month.'-01';
        $runs = DB::table('hr_payroll_runs')->where('organisation_id', $organisationId)->where('month', $first)->get(['id', 'status']);
        $counted = $runs->whereIn('status', ['approved', 'locked'])->pluck('id')->all();
        $earnings = 0;
        $employer = 0;
        $people = 0;
        if ($counted !== []) {
            $rows = DB::table('hr_payroll_run_people')->where('organisation_id', $organisationId)->whereIn('run_id', $counted)->whereNotNull('totals_encrypted')->get(['worker_id', 'totals_encrypted']);
            $workers = [];
            foreach ($rows as $row) {
                try {
                    $totals = json_decode(Crypt::decryptString((string) $row->totals_encrypted), true);
                } catch (DecryptException) {
                    continue;
                }
                if (! is_array($totals)) {
                    continue;
                }
                $earnings += (int) ($totals['earnings'] ?? 0);
                $employer += (int) ($totals['employer'] ?? 0);
                $workers[(int) $row->worker_id] = true;
            }
            $people = count($workers);
        }

        return [
            'runs' => count($counted),
            'waiting' => $runs->count() - count($counted),
            'people' => $people,
            'earnings' => $earnings,
            'employer' => $employer,
            'total' => $earnings + $employer,
            'earningsText' => PayrollMoney::rm($earnings),
            'employerText' => PayrollMoney::rm($employer),
            'totalText' => PayrollMoney::rm($earnings + $employer),
        ];
    }
}
