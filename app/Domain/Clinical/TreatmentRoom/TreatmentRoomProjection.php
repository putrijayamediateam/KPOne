<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecordVoid;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class TreatmentRoomProjection
{
    public function __construct(private TreatmentRoomPolicy $policy, private AuditRecorder $audit) {}

    /** Explicit clinical read only; never attach to generic Visit/Queue serializers or public feeds.
     * @return array{current:list<array<string,mixed>>,history:list<array<string,mixed>>}
     */
    public function forVisit(User $actor, Visit $visit, bool $history = false): array
    {
        $actor = $actor->fresh();
        $visit = $visit->fresh();
        if (! $actor || ! $visit || ! $this->policy->view($actor, $visit, $history)) {
            throw new AuthorizationException('You may not view treatment-room records for this visit.');
        }
        abort_if($this->audit->record('treatment_room.viewed', $visit, ['visit_id' => $visit->id, 'history' => $history], $actor, $visit->branch, $actor->organisation_id) === null, 503, 'Unable to record treatment read audit.');
        $records = TreatmentRoomRecord::query()->where('organisation_id', $actor->organisation_id)->where('visit_id', $visit->id)->orderByDesc('id')->get();
        $voids = TreatmentRoomRecordVoid::query()->where('organisation_id', $actor->organisation_id)->where('visit_id', $visit->id)->get()->keyBy('target_record_id');
        $superseded = $records->pluck('supersedes_record_id')->filter()->all();
        $names = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $records->pluck('recorded_by_user_id')->merge($voids->pluck('voided_by_user_id'))->unique())->pluck('name', 'id');
        $rows = array_values($records->map(function (TreatmentRoomRecord $record) use ($voids, $superseded, $names): array {
            $void = $voids->get($record->id);
            $status = $void ? 'voided' : (in_array($record->id, $superseded, true) ? 'superseded' : 'current');

            return [
                'id' => $record->id,
                'procedureCode' => $record->procedure_code,
                'procedureLabel' => TreatmentRoomVocabulary::PROCEDURES[$record->procedure_code],
                'outcomeCode' => $record->outcome_code,
                'outcomeLabel' => $record->outcome_code === 'done' ? 'Done' : 'Not successful',
                'reasonCode' => $record->reason_code,
                'reasonLabel' => $record->reason_code ? TreatmentRoomVocabulary::REASONS[$record->reason_code] : null,
                'startedAt' => $record->started_at->toIso8601String(),
                'finishedAt' => $record->finished_at->toIso8601String(),
                'recordedAt' => $record->recorded_at->toIso8601String(),
                'recordedBy' => $names->get($record->recorded_by_user_id),
                'status' => $status,
                'supersedesRecordId' => $record->supersedes_record_id,
                'correctionCode' => $record->correction_code,
                'voidReason' => $void ? 'Wrong patient' : null,
                'voidedAt' => $void?->voided_at->toIso8601String(),
                'voidedBy' => $void ? $names->get($void->voided_by_user_id) : null,
            ];
        })->all());

        return ['current' => array_values(array_filter($rows, fn (array $row): bool => $row['status'] === 'current')), 'history' => $rows];
    }
}
