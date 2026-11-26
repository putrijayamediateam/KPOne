<?php

namespace App\Domain\Queue;

use Illuminate\Database\Eloquent\Builder;

/**
 * Q-16a: the one order in which Waiting consultation patients are listed and called.
 *
 * Owner decision (25 Nov 2026): red zone first, then yellow, then everyone else (green, no triage, or no guideline); inside
 * each zone Urgent first, then arrival. A patient from an earlier day still waits behind today's patients (the Call next
 * rule), and carry-over keeps its operational date before arrival.
 *
 * The zone is the latest triage assessment of the visit. It decides ORDER only: no zone ever reaches a viewer through this
 * helper (the chip stays under the TRI-1c rule). The public QR wait estimate, the TV feed, OTC and the Registration board
 * do not use this order on purpose.
 *
 * The query must already join `visits` and select from `queue_entries`.
 */
final class QueueWaitingOrder
{
    /** SQL rank of the visit's latest triage zone: red 0, yellow 1, anything else 2. */
    private const ZONE_RANK = "CASE (SELECT t.chosen_zone FROM visit_triage_assessments t WHERE t.visit_id = visits.id AND t.organisation_id = visits.organisation_id ORDER BY t.sequence DESC LIMIT 1) WHEN 'red' THEN 0 WHEN 'yellow' THEN 1 ELSE 2 END";

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $today, bool $todayFirst = false, bool $byDate = false): Builder
    {
        if ($todayFirst) {
            $query->orderByRaw('CASE WHEN queue_entries.operational_date = ? THEN 0 ELSE 1 END', [$today]);
        }
        $query->orderByRaw(self::ZONE_RANK)
            ->orderByRaw("CASE visits.priority WHEN 'urgent' THEN 0 ELSE 1 END");
        if ($todayFirst || $byDate) {
            $query->orderBy('queue_entries.operational_date');
        }

        return $query->orderBy('queue_entries.queued_at')->orderBy('queue_entries.id');
    }
}
