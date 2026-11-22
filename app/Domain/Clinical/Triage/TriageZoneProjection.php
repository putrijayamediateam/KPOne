<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

/**
 * TRI-1c: the triage zone chip on the Registration board, the Queue board, the Call next card and the consultation page.
 *
 * Owner decision (22 Nov 2026): only the CA and the CA Supervisor (who record triage) and the patient's own doctor see
 * the zone. Everyone else gets no entry at all. Only the zone leaves the server here, never a reading, sign, reason or
 * name. The TV feed and the public QR page never call this.
 */
class TriageZoneProjection
{
    /** The private-board fields of one visit, built from models the caller already loaded (no query). */
    public static function subject(Visit $visit, ?QueueEntry $entry): array
    {
        return [
            'id' => $visit->id,
            'type' => $visit->visit_type,
            'doctor' => $visit->assigned_doctor_user_id,
            'waiting' => $entry?->status === QueueEntry::STATUS_WAITING,
        ];
    }

    /**
     * @param  iterable<array{id:int, type:string|null, doctor:int|null, waiting:bool}>  $subjects
     * @return array<int, array{zone:string|null, triaged:bool}>
     */
    public function forVisits(User $actor, iterable $subjects): array
    {
        if (! $actor->is_active) {
            return [];
        }
        $recorder = $actor->can(TriagePermissions::RECORD_BRANCH);
        $doctor = $actor->can('encounters.view.own');
        $visible = [];
        foreach ($subjects as $subject) {
            if ($subject['type'] !== 'consultation') {
                continue;
            }
            if ($recorder || ($doctor && $subject['doctor'] !== null && $subject['doctor'] === $actor->id)) {
                $visible[$subject['id']] = $subject['waiting'];
            }
        }
        if ($visible === []) {
            return [];
        }

        $latest = VisitTriageAssessment::query()
            ->from('visit_triage_assessments as t')
            ->where('t.organisation_id', $actor->organisation_id)
            ->whereIn('t.visit_id', array_keys($visible))
            ->whereRaw('t.sequence = (SELECT max(m.sequence) FROM visit_triage_assessments m WHERE m.visit_id = t.visit_id)')
            ->pluck('t.chosen_zone', 't.visit_id');

        $result = [];
        foreach ($visible as $visitId => $waiting) {
            if ($latest->has($visitId)) {
                $result[$visitId] = ['zone' => $latest->get($visitId), 'triaged' => true];
            } elseif ($waiting) {
                $result[$visitId] = ['zone' => null, 'triaged' => false];
            }
        }

        return $result;
    }
}
