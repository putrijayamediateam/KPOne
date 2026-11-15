<?php

namespace App\Domain\DepartmentSpace\Explain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * EXPLAIN-1: the one read behind the explainers. It selects times, status, priority and the visit's assigned doctor of
 * the consultation queue (OTC is a separate list and is not read) for one branch, and nothing about the patient. The
 * caller has already checked permission and the active branch.
 *
 * @phpstan-import-type Entry from WaitAnalysis
 */
final class QueueWaitData
{
    /**
     * Entries queued between two instants, with every time in the branch time zone. One query; the branch and
     * operational date columns use the existing queue_entries index.
     *
     * @return list<Entry>
     */
    public function entries(int $branchId, string $timezone, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('queue_entries as q')
            ->join('visits as v', 'v.id', '=', 'q.visit_id')
            ->where('q.branch_id', $branchId)
            ->whereBetween('q.operational_date', [$from->subDay()->toDateString(), $to->addDay()->toDateString()])
            ->get(['q.queued_at', 'q.called_at', 'q.removed_at', 'q.status', 'v.priority', 'v.assigned_doctor_user_id']);

        $local = fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone);
        $entries = [];
        foreach ($rows as $row) {
            $queued = $local($row->queued_at);
            if ($queued === null || $queued < $from || $queued > $to) {
                continue;
            }
            $entries[] = [
                'q' => $queued,
                'c' => $local($row->called_at),
                'r' => $local($row->removed_at),
                's' => (string) $row->status,
                'u' => $row->priority === 'urgent',
                'd' => $row->assigned_doctor_user_id === null ? null : (int) $row->assigned_doctor_user_id,
            ];
        }

        return $entries;
    }
}
