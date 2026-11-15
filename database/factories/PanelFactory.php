<?php

namespace Database\Factories;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Panel> */
class PanelFactory extends Factory
{
    protected $model = Panel::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn () => Organisation::query()->value('id'),
            'code' => fake()->unique()->bothify('SYNTH-###'),
            'name' => 'Synthetic Panel '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
        ];
    }
}
