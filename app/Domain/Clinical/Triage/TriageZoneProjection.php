<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

/**
 * TRI-1c: the triage zone chip on the Registration board, the Queue board, the Call next card and the consultation page.
 *
 * Owner decisions (22 Nov 2026):
 * - the zone is shown only to the CA and the CA Supervisor (who record triage) and to the patient's own doctor;
 * - "No triage yet" (a Waiting consultation with no triage) is shown to them and to anyone who may call that patient,
 *   so nobody calls an untriaged patient unwarned. It carries no zone and no reading.
 * Everyone else gets no entry. Only the zone leaves the server here, never a reading, sign, reason or name. The TV feed
 * and the public QR page never call this.
 */
class TriageZoneProjection
{
    /**
     * The private-board fields of one visit, built from models the caller already loaded (no query).
     *
     * @param  int|null  $doctorId  the patient's own doctor; the consultation page passes the attending clinician
     * @return array{id:int, type:string|null, doctor:int|null, waiting:bool, active:bool}
     */
    public static function subject(Visit $visit, ?QueueEntry $entry, ?int $doctorId = null): array
    {
        return [
            'id' => $visit->id,
            'type' => $visit->visit_type,
            'doctor' => $doctorId ?? $visit->assigned_doctor_user_id,
            'waiting' => $entry?->status === QueueEntry::STATUS_WAITING,
            // Only Waiting and Serving patients carry a chip; a completed or removed row gets none (and costs no query).
            'active' => in_array($entry?->status, [QueueEntry::STATUS_WAITING, QueueEntry::STATUS_SERVING], true),
        ];
    }

    /**
     * @param  iterable<array{id:int, type:string|null, doctor:int|null, waiting:bool, active:bool}>  $subjects
     * @return array<int, array{zone:string|null, triaged:bool}>
     */
    public function forVisits(User $actor, iterable $subjects): array
    {
        if (! $actor->is_active) {
            return [];
        }
        $recorder = $actor->can(TriagePermissions::RECORD_BRANCH);
        // The same role and permission pair as ClinicalEncounterPolicy, so a later grant cannot widen who sees the zone.
        $doctor = $actor->hasRole('resident_doctor') && $actor->can('encounters.view.own');
        $callsBranch = $actor->can('queue.call.branch');
        $callsOwn = $actor->can('queue.call.own');

        $zoneVisible = [];
        $warningOnly = [];
        foreach ($subjects as $subject) {
            if ($subject['type'] !== 'consultation' || ! $subject['active']) {
                continue;
            }
            $own = $subject['doctor'] !== null && $subject['doctor'] === $actor->id;
            if ($recorder || ($doctor && $own)) {
                $zoneVisible[$subject['id']] = $subject['waiting'];
            } elseif ($subject['waiting'] && ($callsBranch || ($callsOwn && $own))) {
                $warningOnly[$subject['id']] = true;
            }
        }
        $ids = [...array_keys($zoneVisible), ...array_keys($warningOnly)];
        if ($ids === []) {
            return [];
        }

        $latest = VisitTriageAssessment::query()
            ->from('visit_triage_assessments as t')
            ->where('t.organisation_id', $actor->organisation_id)
            ->whereIn('t.visit_id', $ids)
            ->whereRaw('t.sequence = (SELECT max(m.sequence) FROM visit_triage_assessments m WHERE m.visit_id = t.visit_id)')
            ->pluck('t.chosen_zone', 't.visit_id');

        $result = [];
        foreach ($zoneVisible as $visitId => $waiting) {
            if ($latest->has($visitId)) {
                $result[$visitId] = ['zone' => $latest->get($visitId), 'triaged' => true];
            } elseif ($waiting) {
                $result[$visitId] = ['zone' => null, 'triaged' => false];
            }
        }
        foreach (array_keys($warningOnly) as $visitId) {
            if (! $latest->has($visitId)) {
                $result[$visitId] = ['zone' => null, 'triaged' => false];
            }
        }

        return $result;
    }
}
