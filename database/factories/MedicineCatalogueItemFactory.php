<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MedicineCatalogueItem> */
class MedicineCatalogueItemFactory extends Factory
{
    protected $model = MedicineCatalogueItem::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn () => User::query()->value('organisation_id'),
            'code' => fake()->unique()->bothify('UAT-MED-####'),
            'display_name' => 'Synthetic medicine '.fake()->unique()->word(),
            'strength_text' => null,
            'dosage_form' => null,
            'order_unit' => 'unit',
            'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true,
            'created_by_user_id' => fn () => User::query()->value('id'),
            'updated_by_user_id' => fn () => User::query()->value('id'),
        ];
    }
}
