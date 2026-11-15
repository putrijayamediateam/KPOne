<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TreatmentPlanService
{
    public function __construct(
        private CurrentClinicalCareService $currentCare,
        private AllergyReviewGate $allergyGate,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function save(User $actor, Visit $visit, array $attributes): ?TreatmentPlan
    {
        $branch = $this->currentCare->activeBranch($actor, $visit, $attributes);

        return DB::transaction(function () use ($actor, $visit, $attributes, $branch): ?TreatmentPlan {
            $care = $this->currentCare->lock($actor, $visit, $branch, 'treatment_plans.view.own');

            $profile = PatientAllergyProfile::query()
                ->where('organisation_id', $care->actor->organisation_id)
                ->where('patient_id', $care->patient->id)
                ->lockForUpdate()
                ->first();
            if ($profile) {
                PatientAllergyRecord::query()
                    ->where('patient_allergy_profile_id', $profile->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }
            $review = ClinicalEncounterAllergyReview::query()
                ->where('clinical_encounter_id', $care->encounter->id)
                ->lockForUpdate()
                ->first();

            $plan = TreatmentPlan::query()
                ->where('clinical_encounter_id', $care->encounter->id)
                ->lockForUpdate()
                ->first();
            $medicineOrders = $plan
                ? TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get()
                : new Collection;
            $serviceOrders = $plan
                ? TreatmentPlanServiceOrder::query()->where('treatment_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get()
                : new Collection;
            $medicineOrders->load('catalogueItem');
            $serviceOrders->load('catalogueItem');

            if ($plan) {
                Gate::forUser($care->actor)->authorize('updateTreatmentPlan', $care->encounter);
                if ($plan->status !== TreatmentPlan::STATUS_IN_PROGRESS) {
                    throw ValidationException::withMessages(['treatment_plan' => 'This Treatment Plan is locked while Dispensary processes it.']);
                }
            } else {
                Gate::forUser($care->actor)->authorize('createTreatmentPlan', $care->encounter);
            }

            $medicines = $this->normalizeMedicines($attributes['medicines']);
            $services = $this->normalizeServices($attributes['services']);
            $medicineChanged = ! $this->medicineStateMatches($medicineOrders, $medicines);
            $medicineSafetyMutation = $this->medicineSafetyMutationRequired($medicineOrders, $medicines);
            $serviceChanged = ! $this->serviceStateMatches($serviceOrders, $services);
            $changed = $medicineChanged || $serviceChanged;

            $expectedVersion = $attributes['lock_version'];
            if ($plan) {
                if ($expectedVersion === null && ! $changed) {
                    return $plan;
                }
                if (! is_int($expectedVersion) || $expectedVersion !== $plan->lock_version) {
                    $this->stale();
                }
                if (! $changed) {
                    return $plan;
                }
            } elseif ($expectedVersion !== null) {
                $this->stale();
            } elseif ($medicines === [] && $services === []) {
                return null;
            }

            if ($medicineSafetyMutation) {
                $this->allergyGate->assertCurrent($care, $profile, $review);
            }
            $allergyVersion = $medicineSafetyMutation ? $profile?->lock_version : null;
            if ($medicineSafetyMutation && $allergyVersion === null) {
                throw new AuthorizationException('A current Allergy Profile is required for medicine orders.');
            }

            $medicineCatalogue = $this->medicineCatalogue($care->actor, $medicines);
            $serviceCatalogue = $this->serviceCatalogue($care->actor, $services);

            $event = 'treatment_plan.updated';
            if (! $plan) {
                $plan = new TreatmentPlan;
                $plan->forceFill([
                    'organisation_id' => $care->actor->organisation_id,
                    'branch_id' => $care->branch->id,
                    'clinical_encounter_id' => $care->encounter->id,
                    'status' => TreatmentPlan::STATUS_IN_PROGRESS,
                    'created_by_user_id' => $care->actor->id,
                    'updated_by_user_id' => $care->actor->id,
                    'lock_version' => 1,
                ])->save();
                $event = 'treatment_plan.created';
            } else {
                $plan->forceFill([
                    'updated_by_user_id' => $care->actor->id,
                    'lock_version' => $plan->lock_version + 1,
                ]);
                $plan->save();
            }

            $this->replaceMedicines($care, $plan, $medicineOrders, $medicines, $medicineCatalogue, $allergyVersion);
            $this->replaceServices($care, $plan, $serviceOrders, $services, $serviceCatalogue);

            $this->audit->record($event, $plan, [
                'record_version' => $plan->lock_version,
                'changed_sections' => array_values(array_filter([
                    $medicineChanged ? 'medicine_orders' : null,
                    $serviceChanged ? 'service_orders' : null,
                ])),
            ], $care->actor, $care->branch);

            return $plan->fresh();
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeMedicines(array $rows): array
    {
        $normalized = array_values(array_map(function (array $row): array {
            $this->requireIdentity($row, 'medicines');

            return [
                'public_id' => $row['public_id'] ?? null,
                'catalogue_public_id' => $row['catalogue_public_id'] ?? null,
                'quantity_ordered' => $this->normalizeQuantity($row['quantity_ordered'], 'medicines'),
                'dosage' => trim($row['dosage']),
                'frequency' => trim($row['frequency']),
                'duration' => $this->nullableText($row['duration'] ?? null),
                'route' => $this->nullableText($row['route'] ?? null),
                'administration_instruction' => $this->nullableText($row['administration_instruction'] ?? null),
                'indication' => $this->nullableText($row['indication'] ?? null),
                'precaution' => $this->nullableText($row['precaution'] ?? null),
            ];
        }, $rows));
        $this->rejectDuplicateExistingIdentities($normalized, 'medicines');

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeServices(array $rows): array
    {
        $normalized = array_values(array_map(function (array $row): array {
            $this->requireIdentity($row, 'services');

            return [
                'public_id' => $row['public_id'] ?? null,
                'catalogue_public_id' => $row['catalogue_public_id'] ?? null,
                'quantity_ordered' => $this->normalizeQuantity($row['quantity_ordered'], 'services'),
                'clinical_instruction' => $this->nullableText($row['clinical_instruction'] ?? null),
            ];
        }, $rows));
        $this->rejectDuplicateExistingIdentities($normalized, 'services');

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function requireIdentity(array $row, string $root): void
    {
        $existing = ! empty($row['public_id']);
        $catalogue = ! empty($row['catalogue_public_id']);
        if ($existing === $catalogue) {
            throw ValidationException::withMessages([$root => 'Each order must reference either one existing order or one catalogue item.']);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function rejectDuplicateExistingIdentities(array $rows, string $root): void
    {
        $ids = array_values(array_filter(array_column($rows, 'public_id')));
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages([$root => 'The same persisted order cannot appear more than once.']);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeQuantity(mixed $value, string $root): string
    {
        $quantity = is_string($value) || is_int($value) || is_float($value)
            ? trim((string) $value)
            : '';

        if (preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $quantity) !== 1
            || (float) $quantity < 0.001) {
            throw ValidationException::withMessages([
                $root => 'Ordered quantity must be between 0.001 and 999999999.999 with at most three decimal places.',
            ]);
        }

        return number_format((float) $quantity, 3, '.', '');
    }

    /**
     * @param  Collection<int, TreatmentPlanMedicineOrder>  $existing
     * @param  list<array<string, mixed>>  $desired
     */
    private function medicineStateMatches(Collection $existing, array $desired): bool
    {
        $active = $existing->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE)->sortBy('position')->values();
        if ($active->count() !== count($desired)) {
            return false;
        }
        foreach ($desired as $index => $row) {
            $order = $active->get($index);
            $identityMatches = $row['public_id']
                ? $order?->public_id === $row['public_id']
                : $order?->catalogueItem?->public_id === $row['catalogue_public_id'];
            if (! $order || ! $identityMatches || $order->position !== $index + 1 || ! $this->sameMedicineValues($order, $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A current Allergy review authorizes new medicine exposure or a clinically
     * meaningful change to active medicine intent. Risk-reducing withdrawal and
     * position-only reordering retain their historical validation evidence.
     *
     * @param  Collection<int, TreatmentPlanMedicineOrder>  $existing
     * @param  list<array<string, mixed>>  $desired
     */
    private function medicineSafetyMutationRequired(Collection $existing, array $desired): bool
    {
        $active = $existing->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE);

        foreach ($desired as $row) {
            if (! $row['public_id']) {
                return true;
            }

            $order = $active->firstWhere('public_id', $row['public_id']);
            if ($order && ! $this->sameMedicineValues($order, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, TreatmentPlanServiceOrder>  $existing
     * @param  list<array<string, mixed>>  $desired
     */
    private function serviceStateMatches(Collection $existing, array $desired): bool
    {
        $active = $existing->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE)->sortBy('position')->values();
        if ($active->count() !== count($desired)) {
            return false;
        }
        foreach ($desired as $index => $row) {
            $order = $active->get($index);
            $identityMatches = $row['public_id']
                ? $order?->public_id === $row['public_id']
                : $order?->catalogueItem?->public_id === $row['catalogue_public_id'];
            if (! $order || ! $identityMatches || $order->position !== $index + 1
                || $order->quantity_ordered !== $row['quantity_ordered']
                || $order->clinical_instruction !== $row['clinical_instruction']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function sameMedicineValues(TreatmentPlanMedicineOrder $order, array $row): bool
    {
        foreach (['quantity_ordered', 'dosage', 'frequency', 'duration', 'route', 'administration_instruction', 'indication', 'precaution'] as $field) {
            if ($order->{$field} !== $row[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, MedicineCatalogueItem>
     */
    private function medicineCatalogue(User $actor, array $rows): Collection
    {
        $ids = array_values(array_filter(array_column($rows, 'catalogue_public_id')));
        $catalogue = MedicineCatalogueItem::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($catalogue->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['medicines' => 'One or more selected medicines are no longer available for ordering.']);
        }

        return $catalogue;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, ClinicalServiceCatalogueItem>
     */
    private function serviceCatalogue(User $actor, array $rows): Collection
    {
        $ids = array_values(array_filter(array_column($rows, 'catalogue_public_id')));
        $catalogue = ClinicalServiceCatalogueItem::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($catalogue->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['services' => 'One or more selected services are no longer available for ordering.']);
        }

        return $catalogue;
    }

    /**
     * @param  Collection<int, TreatmentPlanMedicineOrder>  $existing
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, MedicineCatalogueItem>  $catalogue
     */
    private function replaceMedicines(CurrentClinicalCareContext $care, TreatmentPlan $plan, Collection $existing, array $rows, Collection $catalogue, ?int $allergyVersion): void
    {
        $active = $existing->where('status', TreatmentPlanMedicineOrder::STATUS_ACTIVE);
        $keptIds = array_values(array_filter(array_column($rows, 'public_id')));
        foreach ($active as $index => $order) {
            if (! in_array($order->public_id, $keptIds, true)) {
                $order->forceFill([
                    'status' => TreatmentPlanMedicineOrder::STATUS_WITHDRAWN,
                    'withdrawn_at' => now()->utc(),
                    'withdrawn_by_user_id' => $care->actor->id,
                    'updated_by_user_id' => $care->actor->id,
                ]);
                $order->save();
            } else {
                $order->forceFill(['position' => 30000 + $index]);
                $order->save();
            }
        }

        foreach ($rows as $index => $row) {
            $order = $row['public_id'] ? $active->firstWhere('public_id', $row['public_id']) : null;
            if ($row['public_id'] && ! $order) {
                abort(404);
            }
            $materialChange = ! $order || ! $this->sameMedicineValues($order, $row);
            if (! $order) {
                $item = $catalogue->firstWhere('public_id', $row['catalogue_public_id']);
                $order = new TreatmentPlanMedicineOrder;
                $order->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'organisation_id' => $care->actor->organisation_id,
                    'branch_id' => $care->branch->id,
                    'treatment_plan_id' => $plan->id,
                    'medicine_catalogue_item_id' => $item->id,
                    'medicine_code_snapshot' => $item->code,
                    'medicine_name_snapshot' => $item->display_name,
                    'strength_snapshot' => $item->strength_text,
                    'dosage_form_snapshot' => $item->dosage_form,
                    'unit_snapshot' => $item->order_unit,
                    'status' => TreatmentPlanMedicineOrder::STATUS_ACTIVE,
                    'recorded_by_user_id' => $care->actor->id,
                ]);
            }
            $values = [];
            foreach (['quantity_ordered', 'dosage', 'frequency', 'duration', 'route', 'administration_instruction', 'indication', 'precaution'] as $field) {
                $values[$field] = $row[$field];
            }
            if ($materialChange) {
                $values['allergy_profile_version_validated'] = $allergyVersion;
            }
            $values['position'] = $index + 1;
            $values['updated_by_user_id'] = $care->actor->id;
            $order->forceFill($values);
            $order->save();
        }
    }

    /**
     * @param  Collection<int, TreatmentPlanServiceOrder>  $existing
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, ClinicalServiceCatalogueItem>  $catalogue
     */
    private function replaceServices(CurrentClinicalCareContext $care, TreatmentPlan $plan, Collection $existing, array $rows, Collection $catalogue): void
    {
        $active = $existing->where('status', TreatmentPlanServiceOrder::STATUS_ACTIVE);
        $keptIds = array_values(array_filter(array_column($rows, 'public_id')));
        foreach ($active as $index => $order) {
            if (! in_array($order->public_id, $keptIds, true)) {
                $order->forceFill([
                    'status' => TreatmentPlanServiceOrder::STATUS_WITHDRAWN,
                    'withdrawn_at' => now()->utc(),
                    'withdrawn_by_user_id' => $care->actor->id,
                    'updated_by_user_id' => $care->actor->id,
                ]);
                $order->save();
            } else {
                $order->forceFill(['position' => 30000 + $index]);
                $order->save();
            }
        }
        foreach ($rows as $index => $row) {
            $order = $row['public_id'] ? $active->firstWhere('public_id', $row['public_id']) : null;
            if ($row['public_id'] && ! $order) {
                abort(404);
            }
            if (! $order) {
                $item = $catalogue->firstWhere('public_id', $row['catalogue_public_id']);
                $order = new TreatmentPlanServiceOrder;
                $order->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'organisation_id' => $care->actor->organisation_id,
                    'branch_id' => $care->branch->id,
                    'treatment_plan_id' => $plan->id,
                    'clinical_service_catalogue_item_id' => $item->id,
                    'service_code_snapshot' => $item->code,
                    'service_name_snapshot' => $item->display_name,
                    'unit_snapshot' => $item->order_unit,
                    'status' => TreatmentPlanServiceOrder::STATUS_ACTIVE,
                    'recorded_by_user_id' => $care->actor->id,
                ]);
            }
            $order->forceFill([
                'quantity_ordered' => $row['quantity_ordered'],
                'clinical_instruction' => $row['clinical_instruction'],
                'position' => $index + 1,
                'updated_by_user_id' => $care->actor->id,
            ]);
            $order->save();
        }
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['lock_version' => 'The Treatment Plan changed after it was opened. Reload and review the latest clinical orders.']);
    }
}
