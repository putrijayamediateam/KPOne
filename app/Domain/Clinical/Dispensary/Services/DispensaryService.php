<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Services\CheckoutEvidenceService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispensaryService
{
    public function __construct(private DispensaryAuthorityService $authority, private DispensarySafetyValidator $safety, private InventoryMovementService $inventory, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $attributes */
    public function start(User $actor, DispensaryCase $case, array $attributes): DispensaryCase
    {
        return $this->caTransaction($actor, $case, $attributes, 'dispensary.start.branch', function ($context): void {
            [$actor,$branch,$case,$handoff] = $context;
            if ($case->status !== DispensaryCase::STATUS_PENDING) {
                throw ValidationException::withMessages(['case' => 'This Dispensary case is no longer pending.']);
            }
            $case->forceFill(['status' => DispensaryCase::STATUS_DISPENSING, 'current_handler_user_id' => $actor->id, 'started_at' => now()->utc(), 'lock_version' => $case->lock_version + 1])->save();
            $handoff->forceFill(['started_by_user_id' => $actor->id, 'started_at' => now()->utc()])->save();
            $this->audit->record('dispensary.started', $case, ['record_version' => $case->lock_version], $actor, $branch);
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateItem(User $actor, DispensaryCase $case, DispensaryItem $item, array $attributes): DispensaryItem
    {
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($item, $attributes): void {
            [$actor,$branch,$case,$handoff,$items] = $context;
            $locked = $items->firstWhere('id', $item->id);
            abort_unless($locked && $locked->dispensary_handoff_id === $handoff->id, 404);
            if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->current_handler_user_id !== $actor->id) {
                throw new AuthorizationException('Start and own this Dispensary case before updating it.');
            }
            if ($locked->lock_version !== (int) $attributes['item_lock_version']) {
                $this->stale('item_lock_version');
            }
            [$status,$quantity,$reason] = $this->itemState($locked, $attributes);
            DispensaryItemException::query()->where('dispensary_item_id', $locked->id)->whereIn('status', [DispensaryItemException::STATUS_AWAITING, DispensaryItemException::STATUS_ACKNOWLEDGED])->each(function ($exception): void {
                $exception->forceFill(['status' => DispensaryItemException::STATUS_SUPERSEDED])->save();
            });
            DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $locked->id)->delete();
            $locked->forceFill(['quantity_dispensed' => $quantity, 'status' => $status, 'reason' => $reason, 'handled_by_user_id' => $actor->id, 'handled_at' => now()->utc(), 'lock_version' => $locked->lock_version + 1])->save();
            foreach (($attributes['allocations'] ?? []) as $allocation) {
                $this->allocation($actor, $case, $locked, $allocation);
            }
            if ($reason === 'patient_declined' && in_array($status, [DispensaryItem::STATUS_PARTIAL, DispensaryItem::STATUS_NOT_DISPENSED], true)) {
                $exception = new DispensaryItemException;
                $exception->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'dispensary_case_id' => $case->id, 'dispensary_handoff_id' => $handoff->id, 'dispensary_item_id' => $locked->id, 'proposed_quantity_dispensed' => $quantity, 'reason' => 'patient_declined', 'status' => DispensaryItemException::STATUS_AWAITING, 'expected_case_lock_version' => $case->lock_version + 1, 'expected_item_lock_version' => $locked->lock_version, 'created_by_user_id' => $actor->id])->save();
            }
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.updated', $case, ['record_version' => $case->lock_version, 'changed_sections' => ['fulfilment']], $actor, $branch);
        });

        return $item->fresh(['allocations', 'exceptions']);
    }

    /** @param array<string,mixed> $attributes */
    public function returnToDoctor(User $actor, DispensaryCase $case, array $attributes): DispensaryCase
    {
        return $this->caTransaction($actor, $case, $attributes, 'dispensary.return_to_doctor.branch', function ($context): void {
            [$actor,$branch,$case,$handoff,$items,$visit,$queue,$encounter,$plan] = $context;
            if (! in_array($case->status, [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true) || $handoff->status !== DispensaryHandoff::STATUS_OPEN) {
                $this->stale('case');
            }
            if (DB::table('stock_movements')->whereIn('dispensary_item_batch_allocation_id', DB::table('dispensary_item_batch_allocations')->whereIn('dispensary_item_id', $items->pluck('id'))->select('id'))->exists()) {
                throw ValidationException::withMessages(['case' => 'Stock movement already exists; this case cannot be returned.']);
            }
            $handoff->forceFill(['status' => DispensaryHandoff::STATUS_RETURNED, 'open_case_guard' => null, 'returned_by_user_id' => $actor->id, 'returned_at' => now()->utc()])->save();
            $case->forceFill(['status' => DispensaryCase::STATUS_RETURNED, 'current_handler_user_id' => null, 'returned_at' => now()->utc(), 'lock_version' => $case->lock_version + 1])->save();
            if ($visit->status !== Visit::STATUS_REGISTERED || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS || $plan->status !== TreatmentPlan::STATUS_READY_FOR_DISPENSING || $plan->lock_version !== $handoff->treatment_plan_lock_version_received) {
                $this->stale('case');
            }
            $plan->forceFill(['status' => TreatmentPlan::STATUS_IN_PROGRESS, 'updated_by_user_id' => $actor->id, 'lock_version' => $plan->lock_version + 1])->save();
            if ($queue->status !== QueueEntry::STATUS_REMOVED || $queue->removal_reason !== 'sent_to_dispensary') {
                $this->stale('queue');
            }
            $queue->forceFill(['status' => QueueEntry::STATUS_SERVING, 'removed_at' => null, 'removal_reason' => null, 'returned_from_dispensary_at' => now()->utc(), 'updated_by_user_id' => $actor->id, 'lock_version' => $queue->lock_version + 1])->save();
            app(CheckoutEvidenceService::class)->supersede(ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->first());
            $this->audit->record('dispensary.returned_to_doctor', $case, ['record_version' => $case->lock_version, 'plan_version' => $plan->lock_version], $actor, $branch);
        });
    }

    /** @param array<string,mixed> $attributes */
    public function acknowledge(User $actor, DispensaryItemException $exception, array $attributes): DispensaryItemException
    {
        return DB::transaction(function () use ($actor, $exception, $attributes): DispensaryItemException {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedActor->load(['roles.permissions', 'permissions']);
            $profile = StaffProfile::query()->where('user_id', $lockedActor->id)->lockForUpdate()->first();
            $assignments = $profile ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get() : collect();
            $exceptionStub = DispensaryItemException::query()->whereKey($exception->id)->where('organisation_id', $lockedActor->organisation_id)->firstOrFail();
            $caseStub = DispensaryCase::query()->whereKey($exceptionStub->dispensary_case_id)->where('organisation_id', $lockedActor->organisation_id)->firstOrFail();
            $patient = Patient::query()->whereKey(Visit::query()->whereKey($caseStub->visit_id)->value('patient_id'))->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($caseStub->visit_id)->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = ClinicalEncounter::query()->whereKey($caseStub->clinical_encounter_id)->lockForUpdate()->firstOrFail();
            $case = DispensaryCase::query()->whereKey($caseStub->id)->where('branch_id', $visit->branch_id)->lockForUpdate()->firstOrFail();
            $handoff = DispensaryHandoff::query()->whereKey($exceptionStub->dispensary_handoff_id)->where('dispensary_case_id', $case->id)->where('status', DispensaryHandoff::STATUS_OPEN)->lockForUpdate()->firstOrFail();
            $items = DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->lockForUpdate()->get();
            $item = $items->firstWhere('id', $exceptionStub->dispensary_item_id);
            abort_unless($item !== null, 404);
            $lockedException = DispensaryItemException::query()->whereKey($exceptionStub->id)->where('dispensary_item_id', $item->id)->lockForUpdate()->firstOrFail();
            $branch = $visit->branch()->firstOrFail();
            $date = now()->setTimezone($branch->timezone)->toDateString();
            $assigned = $assignments->contains(fn ($a) => $a->branch_id === $branch->id && $a->valid_from->toDateString() <= $date && ($a->valid_until === null || $a->valid_until->toDateString() >= $date));
            if (! $lockedActor->is_active || ! $profile || ! $lockedActor->hasRole('resident_doctor') || ! $lockedActor->can('dispensary.acknowledge_partial.own') || ! $assigned || $visit->assigned_doctor_user_id !== $lockedActor->id || $encounter->attending_clinician_user_id !== $lockedActor->id || $visit->status !== Visit::STATUS_REGISTERED || $queue->status !== QueueEntry::STATUS_REMOVED || $queue->removal_reason !== 'sent_to_dispensary' || ! in_array($case->status, [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true)) {
                throw new AuthorizationException('You may not acknowledge this Dispensary exception.');
            }
            if ($lockedException->status !== DispensaryItemException::STATUS_AWAITING
                || $lockedException->reason !== DispensaryItemException::REASON_PATIENT_DECLINED
                || $lockedException->proposed_quantity_dispensed !== $item->quantity_dispensed
                || $lockedException->expected_case_lock_version !== $case->lock_version
                || $lockedException->expected_item_lock_version !== $item->lock_version
                || (int) $attributes['case_lock_version'] !== $case->lock_version
                || (int) $attributes['item_lock_version'] !== $item->lock_version) {
                $this->stale('exception');
            }
            $lockedException->forceFill(['status' => DispensaryItemException::STATUS_ACKNOWLEDGED, 'acknowledged_by_user_id' => $lockedActor->id, 'acknowledged_at' => now()->utc()])->save();
            $this->audit->record('dispensary.partial_acknowledged', $case, ['record_version' => $case->lock_version], $lockedActor, $branch);

            return $lockedException->fresh();
        }, 3);
    }

    /** @param array<string,mixed> $attributes */
    public function complete(User $actor, DispensaryCase $case, array $attributes): DispensaryCase
    {
        return $this->caTransaction($actor, $case, $attributes, 'dispensary.complete.branch', function ($context): void {
            [$actor,$branch,$case,$handoff,$items,$visit,$queue,$encounter,$plan,$profile,$review] = $context;
            if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->current_handler_user_id !== $actor->id || $handoff->status !== DispensaryHandoff::STATUS_OPEN || $visit->status !== Visit::STATUS_REGISTERED || $queue->status !== QueueEntry::STATUS_REMOVED || $queue->removal_reason !== 'sent_to_dispensary' || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS) {
                $this->stale('case');
            }
            $this->safety->assertCurrent($encounter, $profile, $review, $plan, $handoff, $items);
            foreach ($items as $item) {
                if ($item->status === DispensaryItem::STATUS_PENDING) {
                    throw ValidationException::withMessages(['items' => 'Finalize every Medicine before completing Dispensary.']);
                }
                if (in_array($item->reason, ['out_of_stock', 'clarification_required', 'other'], true)) {
                    throw ValidationException::withMessages(['items' => 'Stock shortage or clarification requires Return to Doctor.']);
                }
                if (in_array($item->status, [DispensaryItem::STATUS_PARTIAL, DispensaryItem::STATUS_NOT_DISPENSED], true)) {
                    $ack = DispensaryItemException::query()->where('dispensary_item_id', $item->id)->where('status', DispensaryItemException::STATUS_ACKNOWLEDGED)->lockForUpdate()->latest('id')->first();
                    if (! $ack || $ack->proposed_quantity_dispensed !== $item->quantity_dispensed || $ack->expected_item_lock_version !== $item->lock_version) {
                        throw ValidationException::withMessages(['items' => 'The attending doctor must acknowledge the current patient-declined quantity.']);
                    }
                }
            }

            $allocations = DispensaryItemBatchAllocation::query()
                ->whereIn('dispensary_item_id', $items->pluck('id'))
                ->orderBy('inventory_location_id')
                ->orderBy('inventory_sku_id')
                ->orderBy('inventory_batch_id')
                ->lockForUpdate()
                ->get();
            $allocationsByItem = $allocations->groupBy('dispensary_item_id');
            foreach ($items as $item) {
                if (number_format((float) $allocationsByItem->get($item->id, collect())->sum('quantity'), 3, '.', '') !== number_format((float) $item->quantity_dispensed, 3, '.', '')) {
                    throw ValidationException::withMessages(['allocations' => 'Batch allocations must equal the actual dispensed quantity.']);
                }
            }

            $locations = InventoryLocation::query()
                ->where('organisation_id', $actor->organisation_id)
                ->whereIn('id', $allocations->pluck('inventory_location_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $skus = InventorySku::query()
                ->where('organisation_id', $actor->organisation_id)
                ->whereIn('id', $allocations->pluck('inventory_sku_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $mappings = MedicineCatalogueInventorySku::query()
                ->where('organisation_id', $actor->organisation_id)
                ->whereIn('medicine_catalogue_item_id', $items->pluck('medicine_catalogue_item_id')->unique())
                ->whereIn('inventory_sku_id', $allocations->pluck('inventory_sku_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $batches = InventoryBatch::query()
                ->where('organisation_id', $actor->organisation_id)
                ->whereIn('id', $allocations->pluck('inventory_batch_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $localDate = now()->setTimezone($branch->timezone)->toDateString();

            foreach ($allocations as $allocation) {
                $item = $items->firstWhere('id', $allocation->dispensary_item_id);
                $location = $locations->get($allocation->inventory_location_id);
                $sku = $skus->get($allocation->inventory_sku_id);
                $batch = $batches->get($allocation->inventory_batch_id);
                $mapping = $item ? $mappings->first(fn (MedicineCatalogueInventorySku $candidate): bool => $candidate->medicine_catalogue_item_id === $item->medicine_catalogue_item_id && $candidate->inventory_sku_id === $allocation->inventory_sku_id) : null;
                if (! $item
                    || ! $location
                    || ! $location->is_active
                    || $location->branch_id !== $branch->id
                    || $location->type !== InventoryLocation::TYPE_DISPENSARY
                    || ! $sku
                    || ! $sku->is_active
                    || ! $mapping
                    || ! $mapping->is_active
                    || ! $batch
                    || $batch->inventory_sku_id !== $sku->id
                    || $batch->status !== InventoryBatch::STATUS_AVAILABLE
                    || CarbonImmutable::parse((string) $batch->expiry_date, $branch->timezone)->toDateString() <= $localDate) {
                    throw ValidationException::withMessages(['allocations' => 'A selected inventory reference is no longer eligible for dispensing.']);
                }
                $movement = $this->inventory->debitForDispense($actor, $location, $sku, $batch, (string) $allocation->quantity, $allocation->id, $allocation->public_id);
                $this->audit->record('inventory.dispensed', $movement, ['movement_type' => 'dispense'], $actor, $branch);
            }
            $handoff->forceFill(['status' => DispensaryHandoff::STATUS_COMPLETED, 'open_case_guard' => null, 'completed_by_user_id' => $actor->id, 'completed_at' => now()->utc()])->save();
            $case->forceFill(['status' => DispensaryCase::STATUS_COMPLETED, 'completed_at' => now()->utc(), 'lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.completed', $case, ['record_version' => $case->lock_version], $actor, $branch);
        });
    }

    /** @param array<string,mixed> $attributes */
    private function caTransaction(User $actor, DispensaryCase $case, array $attributes, string $permission, callable $callback): DispensaryCase
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $case, $attributes, $permission, $callback, $branch): DispensaryCase {
            $lockedActor = $this->authority->lock($actor, $branch, $permission);
            $lockedCaseStub = DispensaryCase::query()->whereKey($case->id)->where('organisation_id', $lockedActor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            $patient = Patient::query()->whereKey($lockedCaseStub->visit()->value('patient_id'))->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($lockedCaseStub->visit_id)->where('patient_id', $patient->id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = ClinicalEncounter::query()->whereKey($lockedCaseStub->clinical_encounter_id)->lockForUpdate()->firstOrFail();
            $encounter->setRelation('visit', $visit);
            $profile = PatientAllergyProfile::query()->where('patient_id', $patient->id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->first();
            if ($profile) {
                PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get();
            }
            $review = ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first();
            $plan = TreatmentPlan::query()->whereKey($lockedCaseStub->treatment_plan_id)->lockForUpdate()->firstOrFail();
            TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
            ConsultationCheckout::query()->where('visit_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
            $lockedCase = DispensaryCase::query()->whereKey($lockedCaseStub->id)->lockForUpdate()->firstOrFail();
            if ($lockedCase->lock_version !== (int) $attributes['case_lock_version']) {
                $this->stale('case_lock_version');
            }
            $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $lockedCase->id)->where('status', DispensaryHandoff::STATUS_OPEN)->lockForUpdate()->firstOrFail();
            $items = DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->lockForUpdate()->get();
            $callback([$lockedActor, $branch, $lockedCase, $handoff, $items, $visit, $queue, $encounter, $plan, $profile, $review]);

            return $lockedCase->fresh(['handoffs.items']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{string, ?string, ?string}
     */
    private function itemState(DispensaryItem $item, array $a): array
    {
        $status = (string) $a['status'];
        $reason = $a['reason'] ?? null;
        $raw = $a['quantity_dispensed'] ?? null;
        if ($status === DispensaryItem::STATUS_PENDING && ! empty($a['allocations'])) {
            throw ValidationException::withMessages(['allocations' => 'Pending items cannot have stock allocations.']);
        }
        $quantity = $raw === null ? null : number_format((float) $raw, 3, '.', '');
        $ordered = (float) $item->quantity_ordered;
        $actual = $quantity === null ? null : (float) $quantity;
        $valid = match ($status) {
            'pending' => $quantity === null && $reason === null,'dispensed' => $actual === $ordered && $reason === null,'partial' => $actual !== null && $actual > 0 && $actual < $ordered && in_array($reason, ['patient_declined', 'out_of_stock', 'clarification_required', 'other'], true),'not_dispensed' => $actual === 0.0 && in_array($reason, ['patient_declined', 'out_of_stock', 'clarification_required', 'other'], true),default => false
        };
        if (! $valid) {
            throw ValidationException::withMessages(['status' => 'Actual quantity, status and reason are inconsistent.']);
        }

        return [$status, $quantity, $reason];
    }

    /** @param array<string,mixed> $a */
    private function allocation(User $actor, DispensaryCase $case, DispensaryItem $item, array $a): void
    {
        $location = InventoryLocation::query()->where('public_id', $a['location_public_id'])->where('organisation_id', $actor->organisation_id)->where('branch_id', $case->branch_id)->where('type', InventoryLocation::TYPE_DISPENSARY)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $sku = InventorySku::query()->where('public_id', $a['sku_public_id'])->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $mapped = MedicineCatalogueInventorySku::query()->where('organisation_id', $actor->organisation_id)->where('medicine_catalogue_item_id', $item->medicine_catalogue_item_id)->where('inventory_sku_id', $sku->id)->where('is_active', true)->lockForUpdate()->exists();
        abort_unless($mapped, 404);
        $batch = InventoryBatch::query()->where('public_id', $a['batch_public_id'])->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
        $quantity = number_format((float) $a['quantity'], 3, '.', '');
        if ((float) $quantity <= 0) {
            throw ValidationException::withMessages(['allocations' => 'Allocation quantity must be positive.']);
        }
        $allocation = new DispensaryItemBatchAllocation;
        $allocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $case->branch_id, 'dispensary_item_id' => $item->id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'quantity' => $quantity])->save();
    }

    private function stale(string $field): never
    {
        throw ValidationException::withMessages([$field => 'This Dispensary record changed. Reload and review the latest state.']);
    }
}
