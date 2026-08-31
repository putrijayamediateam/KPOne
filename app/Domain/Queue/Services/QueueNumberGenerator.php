<?php

namespace App\Domain\Queue\Services;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class QueueNumberGenerator
{
    public function next(Branch $branch, string $operationalDate): int
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('Queue numbers must be allocated inside a database transaction.');
        }

        $now = now();
        DB::table('queue_number_counters')->insertOrIgnore([
            'organisation_id' => $branch->organisation_id,
            'branch_id' => $branch->id,
            'operational_date' => $operationalDate,
            'next_value' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $counter = DB::table('queue_number_counters')
            ->where('organisation_id', $branch->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('operational_date', $operationalDate)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            throw new RuntimeException('The Queue-number counter could not be initialized.');
        }

        $value = (int) $counter->next_value;
        if ($value < 1 || $value > 99_999_999) {
            throw ValidationException::withMessages([
                'queue' => 'The Queue-number range requires administrator attention.',
            ]);
        }

        DB::table('queue_number_counters')->where('id', $counter->id)->update([
            'next_value' => $value + 1,
            'updated_at' => $now,
        ]);

        return $value;
    }
}
