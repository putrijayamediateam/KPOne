<?php

namespace App\Domain\Queue\Display;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;

/** Counts of branch-local today's call evidence for authorized settings only; never the TV feed. */
class QueueCallStatsService
{
    private const ROOMS_PER_PAGE = 25;

    /** @return array{operationalDate:string,total:int,recalls:int,byService:array<string,array{calls:int,recalls:int}>,byRoom:list<array{room:string,calls:int}>,roomPagination:array{currentPage:int,lastPage:int,perPage:int,total:int}} */
    public function today(Branch $branch, int $roomPage = 1): array
    {
        $today = now()->setTimezone($branch->timezone)->toDateString();
        // One PostgreSQL statement gives totals, page bounds and room rows the same
        // MVCC snapshot, even when a call commits while this read is executing.
        // Only the three constrained services, one metadata row and 25 rooms leave SQL.
        $rows = DB::select(<<<'SQL'
            WITH scoped_calls AS MATERIALIZED (
                SELECT service, is_recall, COALESCE(room_name, 'No room') AS room
                FROM queue_calls WHERE branch_id = ? AND operational_date = ?
            ), rooms AS (
                SELECT room, count(*) AS calls,
                    row_number() OVER (ORDER BY room COLLATE "C") AS position
                FROM scoped_calls GROUP BY room
            ), bounds AS (
                SELECT count(*) AS total,
                    GREATEST(1, CEIL(count(*) / 25.0))::integer AS last_page
                FROM rooms
            ), page AS (
                SELECT total, last_page, LEAST(GREATEST(1, ?), last_page)::integer AS current_page
                FROM bounds
            )
            SELECT * FROM (
            SELECT 'service' AS kind, service AS label,
                count(*) FILTER (WHERE NOT is_recall) AS calls,
                count(*) FILTER (WHERE is_recall) AS recalls
            FROM scoped_calls GROUP BY service
            UNION ALL
            SELECT 'metadata', current_page::text, total, last_page FROM page
            UNION ALL
            SELECT 'room', room, calls, 0 FROM rooms CROSS JOIN page
            WHERE position > (current_page - 1) * 25 AND position <= current_page * 25
            ) AS stats ORDER BY kind, label COLLATE "C"
            SQL, [$branch->id, $today, $roomPage]);
        $byService = [];
        $byRoom = [];
        $total = 0;
        $recalls = 0;
        $roomTotal = 0;
        $lastPage = 1;
        $roomPage = 1;
        foreach ($rows as $row) {
            if ($row->kind === 'metadata') {
                $roomTotal = (int) $row->calls;
                $lastPage = (int) $row->recalls;
                $roomPage = (int) $row->label;
            } elseif ($row->kind === 'room') {
                $byRoom[] = ['room' => (string) $row->label, 'calls' => (int) $row->calls];
            } else {
                $byService[$row->label] = ['calls' => (int) $row->calls, 'recalls' => (int) $row->recalls];
                $total += (int) $row->calls + (int) $row->recalls;
                $recalls += (int) $row->recalls;
            }
        }

        return [
            'operationalDate' => $today,
            'total' => $total,
            'recalls' => $recalls,
            'byService' => $byService,
            'byRoom' => $byRoom,
            'roomPagination' => ['currentPage' => $roomPage, 'lastPage' => $lastPage, 'perPage' => self::ROOMS_PER_PAGE, 'total' => $roomTotal],
        ];
    }
}
