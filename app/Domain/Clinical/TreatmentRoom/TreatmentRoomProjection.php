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
     * @return array<string,mixed>
     */
    public function forVisit(User $actor, Visit $visit, bool $history = false, int $currentPage = 1, int $historyPage = 1): array
    {
        $actor = $actor->fresh();
        $visit = $visit->fresh();
        if (! $actor || ! $visit || ! $this->policy->view($actor, $visit, $history)) {
            throw new AuthorizationException('You may not view treatment-room records for this visit.');
        }
        abort_if($this->audit->record('treatment_room.viewed', $visit, ['visit_id' => $visit->id, 'history' => $history], $actor, $visit->branch, $actor->organisation_id) === null, 503, 'Unable to record treatment read audit.');
        $query = TreatmentRoomRecord::query()->where('organisation_id', $actor->organisation_id)->where('visit_id', $visit->id);
        // Classify against all evidence, never just the selected history page.
        $current = (clone $query)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treatment_room_records as successor')->whereColumn('successor.supersedes_record_id', 'treatment_room_records.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treatment_room_record_voids as void')->whereColumn('void.target_record_id', 'treatment_room_records.id'));
        $currentPagination = TreatmentRoomPagination::metadata($currentPage, (clone $current)->count());
        $historyPagination = TreatmentRoomPagination::metadata($historyPage, (clone $query)->count());
        $currentRecords = $current->orderByDesc('id')->offset(($currentPagination['currentPage'] - 1) * TreatmentRoomPagination::PER_PAGE)->limit(TreatmentRoomPagination::PER_PAGE)->get();
        $historyRecords = $query->orderByDesc('id')->offset(($historyPagination['currentPage'] - 1) * TreatmentRoomPagination::PER_PAGE)->limit(TreatmentRoomPagination::PER_PAGE)->get();
        $records = $currentRecords->merge($historyRecords);
        $voids = TreatmentRoomRecordVoid::query()->whereIn('target_record_id', $records->modelKeys())->get()->keyBy('target_record_id');
        $successors = TreatmentRoomRecord::query()->whereIn('supersedes_record_id', $records->modelKeys())->get(['id', 'supersedes_record_id', 'correction_code'])->keyBy('supersedes_record_id');
        $names = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $records->pluck('recorded_by_user_id')->merge($voids->pluck('voided_by_user_id'))->unique())->pluck('name', 'id');
        $rows = $records->mapWithKeys(function (TreatmentRoomRecord $record) use ($voids, $successors, $names): array {
            $void = $voids->get($record->id);
            $status = $void ? 'voided' : ($successors->has($record->id) ? 'superseded' : 'current');

            return [$record->id => [
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
                'successorCorrectionCode' => $successors->get($record->id)?->correction_code,
                'voidReason' => $void ? 'Wrong patient' : null,
                'voidedAt' => $void?->voided_at->toIso8601String(),
                'voidedBy' => $void ? $names->get($void->voided_by_user_id) : null,
            ]];
        });

        return [
            'current' => $currentRecords->map(fn (TreatmentRoomRecord $record): array => $rows->get($record->id))->all(),
            'history' => $historyRecords->map(fn (TreatmentRoomRecord $record): array => $rows->get($record->id))->all(),
            'currentPagination' => $currentPagination,
            'historyPagination' => $historyPagination,
        ];
    }
}
