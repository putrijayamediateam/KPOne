<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Models\DispensaryServiceLine;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Services\CheckoutEvidenceService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
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
            $this->refuseOtc($case);
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

    /**
     * DS-01a: the CA saves the final version of one line: quantity, the text fields and the batches.
     * The doctor's order is never touched; the CA's version is stored beside it.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function editItem(User $actor, DispensaryCase $case, DispensaryItem $item, array $attributes): DispensaryItem
    {
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($item, $attributes): void {
            [$actor,$branch,$case,$handoff,$items] = $context;
            $locked = $this->lockedLine($actor, $case, $handoff, $items, $item, $attributes);
            $this->saveLine($actor, $branch, $case, $locked, $attributes, $locked->source === DispensaryItem::SOURCE_CA);
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.item_edited', $case, ['record_version' => $case->lock_version, 'change_state' => $locked->change_state], $actor, $branch);
        });

        return $item->fresh(['allocations']);
    }

    /**
     * DS-01a: the CA adds a medicine the doctor did not order. It has no treatment-plan order.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addItem(User $actor, DispensaryCase $case, array $attributes): DispensaryItem
    {
        $created = null;
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($attributes, &$created): void {
            [$actor,$branch,$case,$handoff,$items,,,,,$profile] = $context;
            $this->assertEditable($actor, $case);
            // An OTC case has no doctor to return to: the CA records what the patient said before completing instead.
            if ($case->case_type !== DispensaryCase::TYPE_OTC && (! $profile || $profile->status === PatientAllergyProfile::STATUS_UNKNOWN)) {
                throw ValidationException::withMessages(['allergy_safety' => 'The Allergy Profile is unknown. Return this case to the attending doctor before adding a medicine.']);
            }
            $medicine = MedicineCatalogueItem::query()->where('public_id', (string) ($attributes['medicine_public_id'] ?? ''))->where('organisation_id', $actor->organisation_id)->where('is_active', true)->first();
            if (! $medicine) {
                throw ValidationException::withMessages(['medicine_public_id' => 'Choose an active medicine from the catalogue.']);
            }
            if ($items->contains(fn (DispensaryItem $line): bool => $line->medicine_catalogue_item_id === $medicine->id && $line->change_state !== DispensaryItem::CHANGE_REMOVED)) {
                throw ValidationException::withMessages(['medicine_public_id' => 'This medicine is already on the list. Edit the existing line instead.']);
            }
            $quantity = $this->positiveQuantity($attributes['quantity_dispensed'] ?? null);
            $text = $this->cleanText($attributes);
            if ($text['dosage'] === null || $text['frequency'] === null) {
                throw ValidationException::withMessages(['dosage' => 'Dosage and frequency are required.']);
            }
            $line = new DispensaryItem;
            $line->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'dispensary_handoff_id' => $handoff->id, 'treatment_plan_medicine_order_id' => null, 'medicine_order_public_id' => null,
                'medicine_catalogue_item_id' => $medicine->id, 'medicine_code_snapshot' => $medicine->code, 'medicine_name_snapshot' => $medicine->display_name,
                'strength_snapshot' => $medicine->strength_text, 'dosage_form_snapshot' => $medicine->dosage_form, 'unit_snapshot' => $medicine->order_unit,
                'quantity_ordered' => $quantity, 'dosage' => $text['dosage'], 'frequency' => $text['frequency'], 'duration' => $text['duration'], 'route' => $text['route'],
                'administration_instruction' => $text['administration_instruction'], 'precaution' => $text['precaution'],
                'allergy_profile_version_validated' => $profile->lock_version ?? 0, 'quantity_dispensed' => $quantity, 'status' => DispensaryItem::STATUS_DISPENSED,
                'reason' => null, 'source' => DispensaryItem::SOURCE_CA, 'change_state' => DispensaryItem::CHANGE_ADDED,
                'edited_by_user_id' => $actor->id, 'edited_at' => now()->utc(), 'handled_by_user_id' => $actor->id, 'handled_at' => now()->utc(), 'lock_version' => 1,
            ])->save();
            $this->replaceAllocations($actor, $case, $line, $quantity, $attributes['allocations'] ?? []);
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.item_added', $case, ['record_version' => $case->lock_version], $actor, $branch);
            $created = $line;
        });

        return $created->fresh(['allocations']);
    }

    /**
     * DS-01a: the CA takes a line off the final list. The row stays, marked removed, so the record shows it.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function removeItem(User $actor, DispensaryCase $case, DispensaryItem $item, array $attributes): DispensaryItem
    {
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($item, $attributes): void {
            [$actor,$branch,$case,$handoff,$items] = $context;
            $locked = $this->lockedLine($actor, $case, $handoff, $items, $item, $attributes);
            $this->supersedeExceptions($locked);
            DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $locked->id)->delete();
            $locked->forceFill([
                'quantity_dispensed' => '0.000', 'status' => DispensaryItem::STATUS_NOT_DISPENSED, 'reason' => DispensaryItem::REASON_CA_REMOVED,
                'change_state' => DispensaryItem::CHANGE_REMOVED, 'edited_by_user_id' => $actor->id, 'edited_at' => now()->utc(),
                'handled_by_user_id' => $actor->id, 'handled_at' => now()->utc(), 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.item_removed', $case, ['record_version' => $case->lock_version, 'source' => $locked->source], $actor, $branch);
        });

        return $item->fresh(['allocations']);
    }

    /**
     * @param  Collection<int,DispensaryItem>  $items
     * @param  array<string,mixed>  $attributes
     */
    private function lockedLine(User $actor, DispensaryCase $case, DispensaryHandoff $handoff, $items, DispensaryItem $item, array $attributes): DispensaryItem
    {
        $locked = $items->firstWhere('id', $item->id);
        abort_unless($locked && $locked->dispensary_handoff_id === $handoff->id, 404);
        $this->assertEditable($actor, $case);
        if ($locked->lock_version !== (int) ($attributes['item_lock_version'] ?? 0)) {
            $this->stale('item_lock_version');
        }

        return $locked;
    }

    /** Doctor-ordered workflow only: an OTC case has no doctor, plan, services or return path. */
    private function refuseOtc(DispensaryCase $case): void
    {
        if ($case->case_type === DispensaryCase::TYPE_OTC) {
            throw ValidationException::withMessages(['case' => 'This action does not apply to an OTC Dispensary case.']);
        }
    }

    private function assertEditable(User $actor, DispensaryCase $case): void
    {
        if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->current_handler_user_id !== $actor->id) {
            throw new AuthorizationException('Start and own this Dispensary case before updating it.');
        }
    }

    /** @param array<string,mixed> $attributes */
    private function saveLine(User $actor, Branch $branch, DispensaryCase $case, DispensaryItem $line, array $attributes, bool $caLine): void
    {
        $quantity = $this->positiveQuantity($attributes['quantity_dispensed'] ?? null);
        $text = $this->cleanText($attributes);
        if ($text['dosage'] === null || $text['frequency'] === null) {
            throw ValidationException::withMessages(['dosage' => 'Dosage and frequency are required.']);
        }
        $changed = (float) $quantity !== (float) $line->quantity_ordered;
        $finals = [];
        foreach (DispensaryItem::EDITABLE_TEXT as $field => $final) {
            $differs = $text[$field] !== $this->cleanValue($line->getAttribute($field));
            $finals[$final] = $differs ? $text[$field] : null;
            $changed = $changed || $differs;
        }
        $this->supersedeExceptions($line);
        $this->replaceAllocations($actor, $case, $line, $quantity, $attributes['allocations'] ?? []);
        $line->forceFill([
            ...$finals, 'quantity_dispensed' => $quantity, 'status' => DispensaryItem::STATUS_DISPENSED, 'reason' => null,
            'change_state' => $caLine ? DispensaryItem::CHANGE_ADDED : ($changed ? DispensaryItem::CHANGE_EDITED : DispensaryItem::CHANGE_UNCHANGED),
            'edited_by_user_id' => $actor->id, 'edited_at' => now()->utc(), 'handled_by_user_id' => $actor->id, 'handled_at' => now()->utc(),
            'lock_version' => $line->lock_version + 1,
        ])->save();
    }

    private function supersedeExceptions(DispensaryItem $line): void
    {
        DispensaryItemException::query()->where('dispensary_item_id', $line->id)->whereIn('status', [DispensaryItemException::STATUS_AWAITING, DispensaryItemException::STATUS_ACKNOWLEDGED])->each(function ($exception): void {
            $exception->forceFill(['status' => DispensaryItemException::STATUS_SUPERSEDED])->save();
        });
    }

    /** @param array<int,array<string,mixed>> $allocations */
    private function replaceAllocations(User $actor, DispensaryCase $case, DispensaryItem $line, string $quantity, array $allocations): void
    {
        DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $line->id)->delete();
        $sum = 0.0;
        foreach ($allocations as $allocation) {
            $this->allocation($actor, $case, $line, $allocation);
            $sum += (float) $allocation['quantity'];
        }
        if (number_format($sum, 3, '.', '') !== $quantity) {
            throw ValidationException::withMessages(['allocations' => 'Choose batches that add up to the quantity to dispense.']);
        }
    }

    private function positiveQuantity(mixed $raw): string
    {
        $quantity = is_numeric($raw) ? number_format((float) $raw, 3, '.', '') : null;
        if ($quantity === null || (float) $quantity <= 0) {
            throw ValidationException::withMessages(['quantity_dispensed' => 'Enter a quantity above zero, or remove the medicine from the list.']);
        }

        return $quantity;
    }

    private function cleanValue(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string, ?string>
     */
    private function cleanText(array $attributes): array
    {
        $clean = [];
        foreach (array_keys(DispensaryItem::EDITABLE_TEXT) as $field) {
            $clean[$field] = $this->cleanValue($attributes[$field] ?? null);
        }

        return $clean;
    }

    /**
     * DS-01a-services: the CA saves the final version of one service: how much was performed and the
     * instruction. Confirming performance is the CA's at Dispensary; the doctor's confirmation and the
     * order are never touched.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function editServiceLine(User $actor, DispensaryCase $case, DispensaryServiceLine $line, array $attributes): DispensaryServiceLine
    {
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($line, $attributes): void {
            [$actor,$branch,$case,$handoff] = $context;
            $this->refuseOtc($case);
            $locked = $this->lockedServiceLine($actor, $case, $handoff, $line, $attributes);
            $quantity = $this->nonNegativeQuantity($attributes['quantity_performed'] ?? null);
            $instruction = $this->cleanValue($attributes['clinical_instruction'] ?? null);
            $changed = (float) $quantity !== (float) ($locked->doctor_quantity_performed ?? $locked->quantity_ordered)
                || $instruction !== $this->cleanValue($locked->clinical_instruction);
            $locked->forceFill([
                'quantity_performed' => $quantity, 'disposition' => (float) $quantity > 0 ? DispensaryServiceLine::DISPOSITION_PERFORMED : DispensaryServiceLine::DISPOSITION_NOT_PERFORMED,
                'performed_at' => (float) $quantity > 0 ? now()->utc() : null,
                'final_instruction' => $instruction !== $this->cleanValue($locked->clinical_instruction) ? $instruction : null,
                'change_state' => $locked->source === DispensaryServiceLine::SOURCE_CA ? DispensaryServiceLine::CHANGE_ADDED : ($changed ? DispensaryServiceLine::CHANGE_EDITED : DispensaryServiceLine::CHANGE_UNCHANGED),
                'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->utc(), 'lock_version' => $locked->lock_version + 1,
            ])->save();
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.service_edited', $case, ['record_version' => $case->lock_version, 'change_state' => $locked->change_state], $actor, $branch);
        });

        return $line->fresh();
    }

    /**
     * DS-01a-services: the CA adds a service the doctor did not order. It has no treatment-plan order.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addServiceLine(User $actor, DispensaryCase $case, array $attributes): DispensaryServiceLine
    {
        $created = null;
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($attributes, &$created): void {
            [$actor,$branch,$case,$handoff] = $context;
            $this->refuseOtc($case);
            $this->assertEditable($actor, $case);
            $service = ClinicalServiceCatalogueItem::query()->where('public_id', (string) ($attributes['service_public_id'] ?? ''))->where('organisation_id', $actor->organisation_id)->where('is_active', true)->first();
            if (! $service) {
                throw ValidationException::withMessages(['service_public_id' => 'Choose an active service from the catalogue.']);
            }
            $lines = DispensaryServiceLine::query()->where('dispensary_handoff_id', $handoff->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->contains(fn (DispensaryServiceLine $existing): bool => $existing->clinical_service_catalogue_item_id === $service->id && $existing->change_state !== DispensaryServiceLine::CHANGE_REMOVED)) {
                throw ValidationException::withMessages(['service_public_id' => 'This service is already on the list. Edit the existing line instead.']);
            }
            $quantity = $this->nonNegativeQuantity($attributes['quantity_performed'] ?? null);
            if ((float) $quantity <= 0) {
                throw ValidationException::withMessages(['quantity_performed' => 'Enter a quantity above zero for a service you add.']);
            }
            $instruction = $this->cleanValue($attributes['clinical_instruction'] ?? null);
            $line = new DispensaryServiceLine;
            $line->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'dispensary_handoff_id' => $handoff->id,
                'treatment_plan_service_order_id' => null, 'clinical_service_catalogue_item_id' => $service->id, 'service_code_snapshot' => $service->code,
                'service_name_snapshot' => $service->display_name, 'unit_snapshot' => $service->order_unit, 'quantity_ordered' => $quantity, 'clinical_instruction' => $instruction,
                'doctor_quantity_performed' => null, 'disposition' => DispensaryServiceLine::DISPOSITION_PERFORMED, 'quantity_performed' => $quantity, 'performed_at' => now()->utc(),
                'source' => DispensaryServiceLine::SOURCE_CA, 'change_state' => DispensaryServiceLine::CHANGE_ADDED,
                'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->utc(), 'lock_version' => 1,
            ])->save();
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.service_added', $case, ['record_version' => $case->lock_version], $actor, $branch);
            $created = $line;
        });

        return $created->fresh();
    }

    /**
     * DS-01a-services: the CA takes a service off the final list. The row stays, marked removed.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function removeServiceLine(User $actor, DispensaryCase $case, DispensaryServiceLine $line, array $attributes): DispensaryServiceLine
    {
        $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($line, $attributes): void {
            [$actor,$branch,$case,$handoff] = $context;
            $this->refuseOtc($case);
            $locked = $this->lockedServiceLine($actor, $case, $handoff, $line, $attributes);
            $locked->forceFill([
                'quantity_performed' => '0.000', 'disposition' => DispensaryServiceLine::DISPOSITION_NOT_PERFORMED, 'performed_at' => null,
                'change_state' => DispensaryServiceLine::CHANGE_REMOVED, 'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->utc(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.service_removed', $case, ['record_version' => $case->lock_version, 'source' => $locked->source], $actor, $branch);
        });

        return $line->fresh();
    }

    /** @param array<string,mixed> $attributes */
    private function lockedServiceLine(User $actor, DispensaryCase $case, DispensaryHandoff $handoff, DispensaryServiceLine $line, array $attributes): DispensaryServiceLine
    {
        $locked = DispensaryServiceLine::query()->whereKey($line->id)->where('dispensary_handoff_id', $handoff->id)->lockForUpdate()->first();
        abort_unless($locked !== null, 404);
        $this->assertEditable($actor, $case);
        if ($locked->lock_version !== (int) ($attributes['line_lock_version'] ?? 0)) {
            $this->stale('line_lock_version');
        }

        return $locked;
    }

    private function nonNegativeQuantity(mixed $raw): string
    {
        $quantity = is_numeric($raw) ? number_format((float) $raw, 3, '.', '') : null;
        if ($quantity === null || (float) $quantity < 0 || (float) $quantity > 999999999.999) {
            throw ValidationException::withMessages(['quantity_performed' => 'Enter how much was performed, or 0 if it was not performed.']);
        }

        return $quantity;
    }

    /**
     * DS-01b-1: the CA records what the patient said about allergies and confirms it. This is a
     * recorded confirmation, never an automatic drug-allergy check. It is required before Complete.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function confirmOtcAllergy(User $actor, DispensaryCase $case, array $attributes): DispensaryCase
    {
        return $this->caTransaction($actor, $case, $attributes, 'dispensary.update.branch', function ($context) use ($attributes): void {
            [$actor,$branch,$case,$handoff] = $context;
            if ($case->case_type !== DispensaryCase::TYPE_OTC) {
                throw ValidationException::withMessages(['case' => 'Allergy confirmation by the CA applies to OTC cases only.']);
            }
            $this->assertEditable($actor, $case);
            $statement = $attributes['allergy_statement'] ?? null;
            if (! in_array($statement, ['none', 'has_allergy'], true)) {
                throw ValidationException::withMessages(['allergy_statement' => 'Choose what the patient reported about allergies.']);
            }
            $handoff->forceFill(['otc_allergy_statement' => $statement, 'otc_allergy_confirmed_at' => now()->utc()])->save();
            $case->forceFill(['lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.otc_allergy_confirmed', $case, ['record_version' => $case->lock_version], $actor, $branch);
        });
    }

    /** @param Collection<int,DispensaryItem> $items */
    private function assertOtcCompletable(User $actor, DispensaryCase $case, DispensaryHandoff $handoff, Collection $items, Visit $visit): void
    {
        if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->current_handler_user_id !== $actor->id || $handoff->status !== DispensaryHandoff::STATUS_OPEN
            || $visit->status !== Visit::STATUS_REGISTERED || $visit->visit_type !== 'otc') {
            $this->stale('case');
        }
        if ($handoff->otc_allergy_confirmed_at === null) {
            throw ValidationException::withMessages(['allergy_safety' => 'Confirm what the patient reported about allergies before completing.']);
        }
        if (! $items->contains(fn (DispensaryItem $item): bool => $item->change_state !== DispensaryItem::CHANGE_REMOVED && (float) $item->quantity_dispensed > 0)) {
            throw ValidationException::withMessages(['items' => 'Add at least one medicine to dispense before completing.']);
        }
    }

    /** @param array<string,mixed> $attributes */
    public function returnToDoctor(User $actor, DispensaryCase $case, array $attributes): DispensaryCase
    {
        return $this->caTransaction($actor, $case, $attributes, 'dispensary.return_to_doctor.branch', function ($context): void {
            [$actor,$branch,$case,$handoff,$items,$visit,$queue,$encounter,$plan] = $context;
            $this->refuseOtc($case);
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
            app(ConsultationHoldService::class)->holdReturningConsultation($actor, $branch, $visit, $queue, $encounter);
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
            // DS-01a: pressing Complete is the CA's own verification of the final list, after the doctor's;
            // it is stamped on the handoff below. The doctor's allergy confirmation stands (owner decision).
            if ($case->case_type === DispensaryCase::TYPE_OTC) {
                $this->assertOtcCompletable($actor, $case, $handoff, $items, $visit);
            } else {
                if ($case->status !== DispensaryCase::STATUS_DISPENSING || $case->current_handler_user_id !== $actor->id || $handoff->status !== DispensaryHandoff::STATUS_OPEN || $visit->status !== Visit::STATUS_REGISTERED || $queue->status !== QueueEntry::STATUS_REMOVED || $queue->removal_reason !== 'sent_to_dispensary' || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS) {
                    $this->stale('case');
                }
                $this->safety->assertCurrent($encounter, $profile, $review, $plan, $handoff, $items);
            }
            foreach ($items as $item) {
                if ($item->status === DispensaryItem::STATUS_PENDING) {
                    throw ValidationException::withMessages(['items' => 'Finalize every Medicine before completing Dispensary.']);
                }
                if (in_array($item->reason, ['out_of_stock', 'clarification_required', 'other'], true)) {
                    throw ValidationException::withMessages(['items' => 'Stock shortage or clarification requires Return to Doctor.']);
                }
                if (in_array($item->status, [DispensaryItem::STATUS_PARTIAL, DispensaryItem::STATUS_NOT_DISPENSED], true) && $item->change_state !== DispensaryItem::CHANGE_REMOVED) {
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
            $handoff->forceFill(['status' => DispensaryHandoff::STATUS_COMPLETED, 'open_case_guard' => null, 'completed_by_user_id' => $actor->id, 'completed_at' => now()->utc(), 'ca_verified_at' => now()->utc()])->save();
            $case->forceFill(['status' => DispensaryCase::STATUS_COMPLETED, 'completed_at' => now()->utc(), 'lock_version' => $case->lock_version + 1])->save();
            $this->audit->record('dispensary.completed', $case, [
                'record_version' => $case->lock_version, 'ca_verified' => true,
                'edited_lines' => $items->where('change_state', DispensaryItem::CHANGE_EDITED)->count(),
                'added_lines' => $items->where('change_state', DispensaryItem::CHANGE_ADDED)->count(),
                'removed_lines' => $items->where('change_state', DispensaryItem::CHANGE_REMOVED)->count(),
                'service_lines_changed' => DispensaryServiceLine::query()->where('dispensary_handoff_id', $handoff->id)->where('change_state', '<>', DispensaryServiceLine::CHANGE_UNCHANGED)->count(),
            ], $actor, $branch);
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
            $otc = $lockedCaseStub->case_type === DispensaryCase::TYPE_OTC;
            $queue = $otc ? null : QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = $otc ? null : ClinicalEncounter::query()->whereKey($lockedCaseStub->clinical_encounter_id)->lockForUpdate()->firstOrFail();
            $encounter?->setRelation('visit', $visit);
            $profile = PatientAllergyProfile::query()->where('patient_id', $patient->id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->first();
            if ($profile) {
                PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get();
            }
            $review = $encounter ? ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first() : null;
            $plan = $otc ? null : TreatmentPlan::query()->whereKey($lockedCaseStub->treatment_plan_id)->lockForUpdate()->firstOrFail();
            if ($plan) {
                TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
                ConsultationCheckout::query()->where('visit_id', $visit->id)->orderBy('id')->lockForUpdate()->get();
            }
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
