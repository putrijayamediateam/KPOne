<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TreatmentPlanMedicineOrder> */
class TreatmentPlanMedicineOrderFactory extends Factory
{
    protected $model = TreatmentPlanMedicineOrder::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn (array $a) => $this->plan($a)->organisation_id,
            'branch_id' => fn (array $a) => $this->plan($a)->branch_id,
            'medicine_code_snapshot' => fn (array $a) => $this->catalogue($a)->code,
            'medicine_name_snapshot' => fn (array $a) => $this->catalogue($a)->display_name,
            'strength_snapshot' => fn (array $a) => $this->catalogue($a)->strength_text,
            'dosage_form_snapshot' => fn (array $a) => $this->catalogue($a)->dosage_form,
            'unit_snapshot' => fn (array $a) => $this->catalogue($a)->order_unit,
            'quantity_ordered' => 1,
            'dosage' => 'Synthetic dosage',
            'frequency' => 'Synthetic frequency',
            'allergy_profile_version_validated' => 1,
            'position' => 1,
            'status' => TreatmentPlanMedicineOrder::STATUS_ACTIVE,
            'recorded_by_user_id' => fn (array $a) => $this->plan($a)->created_by_user_id,
            'updated_by_user_id' => fn (array $a) => $this->plan($a)->updated_by_user_id,
        ];
    }

    /** @param array<string, mixed> $a */
    private function plan(array $a): TreatmentPlan
    {
        return TreatmentPlan::query()->whereKey($a['treatment_plan_id'])->firstOrFail();
    }

    /** @param array<string, mixed> $a */
    private function catalogue(array $a): MedicineCatalogueItem
    {
        return MedicineCatalogueItem::query()->whereKey($a['medicine_catalogue_item_id'])->firstOrFail();
    }
}
