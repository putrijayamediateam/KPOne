<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ClinicalServiceCatalogueItem> */
class ClinicalServiceCatalogueItemFactory extends Factory
{
    protected $model = ClinicalServiceCatalogueItem::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn () => User::query()->value('organisation_id'),
            'code' => fake()->unique()->bothify('UAT-SVC-####'),
            'display_name' => 'Synthetic service '.fake()->unique()->word(),
            'order_unit' => 'service',
            'is_active' => true,
            'created_by_user_id' => fn () => User::query()->value('id'),
            'updated_by_user_id' => fn () => User::query()->value('id'),
        ];
    }
}
