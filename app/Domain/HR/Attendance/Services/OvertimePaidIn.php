<?php

namespace App\Domain\HR\Attendance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR month dry run M1 (owner, 16 Nov 2026): approved overtime that a locked payroll run already paid. Reversing it is
 * still allowed, but the manager is told which run paid it, so a recovery goes on an adjustment run. Reads only.
 */
final class OvertimePaidIn
{
    /**
     * The locked run that paid each request (the latest, if more than one), as its id and a label such as
     * "October 2026 payroll run".
     *
     * @param  list<int>  $requestIds
     * @return array<int, array{runId: int, label: string}>
     */
    public function runs(int $organisationId, array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }
        $rows = DB::table('hr_payroll_lines as l')
            ->join('hr_payroll_run_people as p', 'p.id', '=', 'l.run_person_id')
            ->join('hr_payroll_runs as r', 'r.id', '=', 'p.run_id')
            ->where('r.organisation_id', $organisationId)->where('r.status', 'locked')
            ->where('l.source_kind', 'overtime')->whereIn('l.source_id', $requestIds)
            ->orderBy('r.id')->get(['l.source_id', 'r.id as run_id', 'r.month', 'r.kind']);
        $out = [];
        foreach ($rows as $row) {
            $month = CarbonImmutable::parse((string) $row->month)->format('F Y');
            $out[(int) $row->source_id] = ['runId' => (int) $row->run_id,
                'label' => $month.($row->kind === 'adjustment' ? ' adjustment run' : ' payroll run')];
        }

        return $out;
    }
}
