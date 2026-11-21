<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Clinical\Dispensary\DispensaryPackDisplay;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheck;
use App\Domain\Clinical\Dispensary\Models\DispensaryServiceLine;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * DSP-25 (owner decision 4; answers P5, 15 = A, 16 = A): the Director's read-only view of a dispensary case, at any
 * branch. Every open is recorded first (ids only); only then is the page data built.
 *
 * The data is an explicit list of fields for every case status. It reuses neither the open-case `detail()` nor the
 * Completed page's data, so it carries no URL, lock version, token, idempotency field, stock availability or `can`
 * flag: there is nothing a form could post. A test pins the keys.
 */
final class DispensaryReviewService
{
    public const PERMISSION = 'dispensary.view_readonly.organisation';

    public const RETURNS_SHOWN = 10;

    public function __construct(private ReadOnlyReviewAudit $audit) {}

    /** @return array<string, mixed> */
    public function open(User $actor, DispensaryCase $case): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException;
        }
        abort_unless($case->organisation_id === $actor->organisation_id, 404);

        $case->loadMissing('visit:id,organisation_id,branch_id,patient_id,visit_number');
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($case->branch_id)->firstOrFail();

        $this->audit->record($actor, 'dispensary.case_reviewed', $case, [
            'case' => $case->id,
            'visit' => $case->visit_id,
            'patient' => $case->visit->patient_id,
        ], $branch);

        return $this->project($actor, $case, $branch);
    }

    /** @return array<string, mixed> */
    private function project(User $actor, DispensaryCase $case, Branch $branch): array
    {
        $case->load(['visit.patient:id,patient_number,full_name', 'visit.assignedDoctor:id,name']);
        $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->orderByDesc('attempt_number')->first();
        $items = $handoff
            ? DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->with('allocations.batch:id,batch_number,expiry_date')->get()
            : collect();
        $packs = DispensaryPackDisplay::forItems($items);
        $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $case->visit->patient_id)->first();
        // Daniel: the latest 10 returns, newest first, with the full count for a "Showing the latest 10 of N" line.
        $returnsTotal = DispensaryReturn::query()->where('dispensary_case_id', $case->id)->count();
        $returns = DispensaryReturn::query()->where('dispensary_case_id', $case->id)->orderByDesc('id')->limit(self::RETURNS_SHOWN)->get();
        $checks = DispensaryReturnCheck::query()->whereIn('dispensary_return_id', $returns->pluck('id'))->get()->keyBy('dispensary_return_id');
        $time = fn ($value): ?string => $value === null ? null : CarbonImmutable::parse($value)->setTimezone($branch->timezone)->format('j M Y, g:i A');

        return [
            'status' => $case->status,
            'caseType' => $case->case_type,
            'branch' => $branch->name,
            'patient' => ['patientNumber' => $case->visit->patient->patient_number, 'name' => $case->visit->patient->full_name],
            'visit' => ['visitNumber' => $case->visit->visit_number],
            'doctor' => $case->visit->assignedDoctor?->name,
            'receivedAt' => $time($case->received_at),
            'completedAt' => $time($case->completed_at),
            'allergies' => [
                'status' => $profile ? $profile->status : PatientAllergyProfile::STATUS_UNKNOWN,
                'records' => $profile
                    ? $profile->allergyRecords()->where('status', 'active')->orderBy('id')->get(['allergen_text', 'reaction_text', 'severity'])
                        ->map(fn ($a): array => ['allergen' => $a->allergen_text, 'reaction' => $a->reaction_text, 'severity' => $a->severity])->values()->all()
                    : [],
            ],
            'medicines' => $items->map(fn (DispensaryItem $item): array => [
                'name' => $item->medicine_name_snapshot,
                'strength' => $item->strength_snapshot,
                'unit' => $item->unit_snapshot,
                'source' => $item->source,
                'changeState' => $item->change_state,
                'status' => $item->status,
                'ordered' => [
                    'quantity' => $item->quantity_ordered,
                    'dosage' => $item->dosage,
                    'frequency' => $item->frequency,
                    'duration' => $item->duration,
                    'instruction' => $item->administration_instruction,
                ],
                'dispensed' => [
                    'quantity' => $item->quantity_dispensed,
                    'packText' => $packs[$item->id]['text'] ?? null,
                    'dosage' => $item->effective('dosage'),
                    'frequency' => $item->effective('frequency'),
                    'duration' => $item->effective('duration'),
                    'instruction' => $item->effective('administration_instruction'),
                ],
                'batches' => $item->allocations->map(fn (DispensaryItemBatchAllocation $a): array => [
                    'batchNumber' => $a->batch?->batch_number,
                    'expiryDate' => $a->batch?->expiry_date ? CarbonImmutable::parse($a->batch->expiry_date)->format('j M Y') : null,
                    'quantity' => $a->quantity,
                ])->values()->all(),
            ])->values()->all(),
            'services' => $handoff
                ? DispensaryServiceLine::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->get()->map(fn (DispensaryServiceLine $line): array => [
                    'name' => $line->service_name_snapshot,
                    'unit' => $line->unit_snapshot,
                    'source' => $line->source,
                    'changeState' => $line->change_state,
                    'quantityOrdered' => $line->quantity_ordered,
                    'quantityPerformed' => $line->quantity_performed,
                ])->values()->all()
                : [],
            'returns' => $returns->map(fn (DispensaryReturn $return): array => [
                'medicine' => $items->firstWhere('id', $return->dispensary_item_id)?->medicine_name_snapshot,
                'unit' => $items->firstWhere('id', $return->dispensary_item_id)?->unit_snapshot,
                'quantity' => $return->quantity,
                'reason' => $return->reason,
                'condition' => $return->condition,
                'outcome' => $checks->get($return->id)?->outcome,
                'recordedAt' => $time($return->recorded_at),
            ])->values()->all(),
            'returnsTotal' => $returnsTotal,
        ];
    }
}
