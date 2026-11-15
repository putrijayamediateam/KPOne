<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TreatmentPlanServiceOrder> */
class TreatmentPlanServiceOrderFactory extends Factory
{
    protected $model = TreatmentPlanServiceOrder::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn (array $a) => $this->plan($a)->organisation_id,
            'branch_id' => fn (array $a) => $this->plan($a)->branch_id,
            'service_code_snapshot' => fn (array $a) => $this->catalogue($a)->code,
            'service_name_snapshot' => fn (array $a) => $this->catalogue($a)->display_name,
            'unit_snapshot' => fn (array $a) => $this->catalogue($a)->order_unit,
            'quantity_ordered' => 1,
            'position' => 1,
            'status' => TreatmentPlanServiceOrder::STATUS_ACTIVE,
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
    private function catalogue(array $a): ClinicalServiceCatalogueItem
    {
        return ClinicalServiceCatalogueItem::query()->whereKey($a['clinical_service_catalogue_item_id'])->firstOrFail();
    }
}
