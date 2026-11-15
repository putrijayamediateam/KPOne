<?php

namespace Database\Factories;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueueEntry> */
class QueueEntryFactory extends Factory
{
    protected $model = QueueEntry::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $attributes) => $this->visit($attributes)->organisation_id,
            'branch_id' => fn (array $attributes) => $this->visit($attributes)->branch_id,
            'operational_date' => fn (array $attributes) => now()
                ->setTimezone($this->visit($attributes)->branch->timezone)
                ->toDateString(),
            'queue_number' => fake()->unique()->numberBetween(1, 9999),
            'status' => QueueEntry::STATUS_WAITING,
            'queued_at' => now()->utc(),
            'queued_by_user_id' => fn (array $attributes) => $this->visit($attributes)->registered_by_user_id,
            'called_at' => null,
            'called_by_user_id' => null,
            'removed_at' => null,
            'updated_by_user_id' => fn (array $attributes) => $this->visit($attributes)->updated_by_user_id,
            'lock_version' => 1,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function visit(array $attributes): Visit
    {
        return Visit::query()->whereKey($attributes['visit_id'])->firstOrFail();
    }
}
