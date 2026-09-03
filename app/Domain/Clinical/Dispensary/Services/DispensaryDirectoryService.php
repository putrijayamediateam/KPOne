<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryAvailabilityService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DispensaryDirectoryService
{
    public function __construct(private BranchAccessService $branches, private InventoryAvailabilityService $availability) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function board(User $actor, array $criteria): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $actor->can('dispensary.view.branch') && $actor->hasAnyRole(['ca', 'ca_supervisor']), 404);
        $query = DispensaryCase::query()->where('dispensary_cases.organisation_id', $actor->organisation_id)->where('dispensary_cases.branch_id', $branch->id)->whereIn('dispensary_cases.status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING])
            ->with(['visit.patient:id,patient_number,full_name', 'visit.assignedDoctor:id,name', 'handoffs' => fn ($q) => $q->where('status', DispensaryHandoff::STATUS_OPEN)->withCount('items')]);
        $term = trim((string) ($criteria['patient_query'] ?? ''));
        if ($term !== '') {
            $query->whereHas('visit.patient', fn ($q) => preg_match('/\AKP-\d{8}\z/i', $term) === 1 ? $q->where('patient_number', Str::upper($term)) : $q->whereRaw("search_name LIKE ? ESCAPE '\\'", ['%'.$this->escapeLike(Str::lower($term)).'%']));
        }
        if (filled($criteria['doctor_id'] ?? null)) {
            $query->whereHas('visit', fn ($q) => $q->where('assigned_doctor_user_id', (int) $criteria['doctor_id']));
        }
        $paginator = $query->latest('received_at')->paginate(25, page: (int) ($criteria['page'] ?? 1));

        return ['data' => $paginator->getCollection()->map(function (DispensaryCase $case) use ($branch): array {
            $visit = $case->visit;
            $handoff = $case->handoffs->first();

            $receivedAt = CarbonImmutable::parse($case->received_at);

            return ['visitNumber' => $visit->visit_number, 'patientNumber' => $visit->patient->patient_number, 'patientName' => $visit->patient->full_name, 'visitType' => $visit->visit_type, 'registeredAt' => $receivedAt->setTimezone($branch->timezone)->format('H:i'), 'visitReasonExcerpt' => null, 'doctorName' => $visit->assignedDoctor?->name, 'coverageLabel' => $visit->coverage_type === 'panel' ? $visit->coverage_panel_name_snapshot : 'Self-pay', 'priority' => $visit->priority, 'status' => $visit->status, 'queueNumber' => null, 'queueStatus' => 'removed', 'durationMinutes' => max(0, (int) $receivedAt->diffInMinutes(now()->utc())), 'visitLockVersion' => $visit->lock_version, 'queueLockVersion' => null, 'dispensaryStatus' => $case->status, 'medicineCount' => (int) $handoff->getAttribute('items_count'), 'dispensaryUrl' => route('dispensary.show', $case), 'can' => ['viewPatient' => false, 'update' => false, 'cancel' => false, 'sendToWaiting' => false, 'call' => false, 'openConsultation' => false, 'openDispensary' => true]];
        })->values(), 'total' => $paginator->total(), 'currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage()];
    }

    /** @return array<string,mixed> */
    public function detail(User $actor, DispensaryCase $case): array
    {
        $branch = $this->authorizedBranch($actor, $case);
        if ($case->status === DispensaryCase::STATUS_COMPLETED) {
            $case->load('visit.patient:id,patient_number,full_name');

            return [
                'status' => DispensaryCase::STATUS_COMPLETED,
                'patient' => ['patientNumber' => $case->visit->patient->patient_number, 'name' => $case->visit->patient->full_name],
                'visit' => ['visitNumber' => $case->visit->visit_number],
                'completedAt' => $case->completed_at === null ? null : CarbonImmutable::parse($case->completed_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
            ];
        }
        $case->load(['visit.patient:id,patient_number,full_name', 'visit.assignedDoctor:id,name', 'encounter', 'treatmentPlan', 'handoffs' => fn ($q) => $q->where('status', DispensaryHandoff::STATUS_OPEN)->with(['items.allocations', 'items.exceptions'])]);
        $handoff = $case->handoffs->first();
        abort_unless($handoff !== null, 404);
        $profile = PatientAllergyProfile::query()->where('organisation_id', $actor->organisation_id)->where('patient_id', $case->visit->patient_id)->first();

        $firstItem = $handoff->items->first();

        $ownsDispensingCase = $case->status === DispensaryCase::STATUS_DISPENSING && $case->current_handler_user_id === $actor->id;

        return ['publicId' => $case->public_id, 'status' => $case->status, 'lockVersion' => $case->lock_version, 'receivedAt' => CarbonImmutable::parse($case->received_at)->setTimezone($branch->timezone)->toIso8601String(), 'patient' => ['patientNumber' => $case->visit->patient->patient_number, 'name' => $case->visit->patient->full_name], 'visit' => ['visitNumber' => $case->visit->visit_number], 'doctor' => $case->visit->assignedDoctor?->name, 'allergySafety' => ['status' => $profile ? $profile->status : PatientAllergyProfile::STATUS_UNKNOWN, 'profileVersion' => $profile?->lock_version, 'isCurrent' => $profile !== null && $firstItem !== null && $profile->lock_version === $firstItem->allergy_profile_version_validated], 'items' => $handoff->items->map(function (DispensaryItem $item) use ($actor, $case): array {
            $mapping = MedicineCatalogueInventorySku::query()->where('organisation_id', $actor->organisation_id)->where('medicine_catalogue_item_id', $item->medicine_catalogue_item_id)->where('is_active', true)->with('sku')->first();

            return ['publicId' => $item->public_id, 'lockVersion' => $item->lock_version, 'name' => $item->medicine_name_snapshot, 'code' => $item->medicine_code_snapshot, 'strength' => $item->strength_snapshot, 'dosageForm' => $item->dosage_form_snapshot, 'unit' => $item->unit_snapshot, 'quantityOrdered' => $item->quantity_ordered, 'quantityDispensed' => $item->quantity_dispensed, 'status' => $item->status, 'reason' => $item->reason, 'dosage' => $item->dosage, 'frequency' => $item->frequency, 'duration' => $item->duration, 'route' => $item->route, 'instruction' => $item->administration_instruction, 'precaution' => $item->precaution, 'sku' => $mapping ? ['publicId' => $mapping->sku->public_id, 'unit' => $mapping->sku->dispensing_unit] : null, 'availability' => $mapping ? $this->availability->forSku($actor, $mapping->inventory_sku_id, $case->branch_id, dispensaryOnly: true) : [], 'allocations' => $item->allocations->map(fn ($a) => ['locationPublicId' => $a->location?->public_id, 'skuPublicId' => $a->sku?->public_id, 'batchPublicId' => $a->batch?->public_id, 'quantity' => $a->quantity])->values(), 'exception' => $item->exceptions->whereIn('status', ['awaiting_acknowledgement', 'acknowledged'])->last()?->only(['public_id', 'status', 'proposed_quantity_dispensed'])];
        })->values(), 'can' => ['start' => $actor->can('dispensary.start.branch') && $case->status === DispensaryCase::STATUS_PENDING, 'update' => $actor->can('dispensary.update.branch') && $ownsDispensingCase, 'complete' => $actor->can('dispensary.complete.branch') && $ownsDispensingCase, 'return' => $actor->can('dispensary.return_to_doctor.branch') && in_array($case->status, [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true)]];
    }

    /** @return array<string, mixed> */
    public function labels(User $actor, DispensaryCase $case, ?string $itemPublicId = null): array
    {
        $branch = $this->authorizedBranch($actor, $case);
        abort_unless(in_array($case->status, [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true), 404);
        $handoff = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->first();
        abort_unless($handoff !== null, 404);
        $items = $handoff->items()->where('status', '!=', DispensaryItem::STATUS_NOT_DISPENSED)
            ->when($itemPublicId !== null, fn ($query) => $query->where('public_id', $itemPublicId))
            ->orderBy('id')->get();
        abort_if($items->isEmpty(), 404);
        $case->load('visit.patient:id,patient_number,full_name');

        // Explicit saved snapshot projection only. Printing never stages or completes fulfilment.
        return [
            'clinic' => $branch->organisation->name,
            'branch' => $branch->name,
            'patient' => ['name' => $case->visit->patient->full_name, 'patientNumber' => $case->visit->patient->patient_number],
            'date' => now()->setTimezone($branch->timezone)->format('j M Y'),
            'items' => $items->map(fn (DispensaryItem $item): array => [
                'name' => $item->medicine_name_snapshot,
                'strength' => $item->strength_snapshot,
                'quantity' => $item->quantity_dispensed,
                'unit' => $item->unit_snapshot,
                'dosage' => $item->dosage,
                'frequency' => $item->frequency,
                'duration' => $item->duration,
                'instruction' => $item->administration_instruction,
            ])->values()->all(),
        ];
    }

    private function authorizedBranch(User $actor, DispensaryCase $case): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $actor->can('dispensary.view.branch') && $actor->hasAnyRole(['ca', 'ca_supervisor']) && $case->organisation_id === $actor->organisation_id && $case->branch_id === $branch->id, 404);

        return $branch;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
