<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryDoctorCheck;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * DSP-23: read-only projections for the doctor's check.
 *
 * - waiting(): the branch list "Waiting for doctor's check" for a CA Supervisor who may check instead (13 = B).
 * - latestSendBack(): the doctor's last Send back on a case, for the warning box at the top of the CA's page.
 *
 * Notes are encrypted and shown only to staff who already see the case; they never reach audit.
 */
final class DispensaryDoctorCheckDirectory
{
    public const LIMIT = 50;

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function waiting(User $actor, Branch $branch): array
    {
        $query = DispensaryCase::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK);
        $total = (clone $query)->count();
        $cases = $query->orderBy('sent_for_check_at')->orderBy('id')->limit(self::LIMIT)
            ->with(['visit:id,patient_id,visit_number,assigned_doctor_user_id', 'visit.patient:id,full_name,patient_number', 'visit.assignedDoctor:id,name'])->get();
        $senders = User::query()->whereIn('id', $cases->pluck('sent_for_check_by_user_id')->filter()->unique())->pluck('name', 'id');

        return [
            'rows' => $cases->map(fn (DispensaryCase $case): array => [
                'publicId' => $case->public_id,
                'url' => route('dispensary.show', $case),
                'patient' => $case->visit->patient?->full_name,
                'patientNumber' => $case->visit->patient?->patient_number,
                'visitNumber' => $case->visit->visit_number,
                'doctor' => $case->visit->assignedDoctor?->name,
                'sentBy' => $senders->get($case->sent_for_check_by_user_id),
                'sentAt' => $this->time($case->sent_for_check_at, $branch),
                'sentAtIso' => $case->sent_for_check_at?->toIso8601String(),
                'preparedByYou' => $case->sent_for_check_by_user_id === $actor->id || $case->current_handler_user_id === $actor->id,
            ])->values()->all(),
            'total' => $total,
        ];
    }

    /** @return array{reason: string, note: ?string, by: ?string, at: ?string, checkedAs: string}|null */
    public function latestSendBack(DispensaryCase $case, Branch $branch): ?array
    {
        if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->doctor_check_required !== true) {
            return null;
        }
        $check = DispensaryDoctorCheck::query()->where('dispensary_case_id', $case->id)->orderByDesc('id')->first();
        if (! $check || $check->outcome !== DispensaryDoctorCheck::OUTCOME_SENT_BACK) {
            return null;
        }

        return [
            'reason' => (string) $check->reason,
            'note' => $check->note_encrypted,
            'by' => User::query()->whereKey($check->checked_by_user_id)->value('name'),
            'at' => $this->time($check->checked_at, $branch),
            'checkedAs' => $check->checked_as,
        ];
    }

    /** @param Collection<int, int> $caseIds @return array<int, true> */
    public function waitingCaseIds(Collection $caseIds): array
    {
        return DispensaryCase::query()->whereIn('id', $caseIds)->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /** "10:42" today, otherwise "20 Nov 2026, 10:42" (Daniel, note 5). */
    public function time(?CarbonImmutable $value, Branch $branch): ?string
    {
        if ($value === null) {
            return null;
        }
        $local = $value->setTimezone($branch->timezone);

        return $local->isSameDay(now()->setTimezone($branch->timezone)) ? $local->format('g:i A') : $local->format('j M Y, g:i A');
    }
}
