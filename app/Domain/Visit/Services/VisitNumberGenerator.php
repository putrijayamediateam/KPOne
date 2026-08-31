<?php

namespace App\Domain\Visit\Services;

use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VisitNumberGenerator
{
    public function next(Organisation $organisation): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('Visit numbers must be allocated inside a database transaction.');
        }

        $now = now();
        DB::table('visit_number_counters')->insertOrIgnore([
            'organisation_id' => $organisation->id,
            'next_value' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $counter = DB::table('visit_number_counters')
            ->where('organisation_id', $organisation->id)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            throw new RuntimeException('The visit-number counter could not be initialized.');
        }

        $value = (int) $counter->next_value;
        if ($value < 1 || $value > 99_999_999) {
            throw ValidationException::withMessages(['visit' => 'The visit-number range requires administrator attention.']);
        }

        DB::table('visit_number_counters')->where('organisation_id', $organisation->id)->update([
            'next_value' => $value + 1,
            'updated_at' => $now,
        ]);

        return sprintf('KPV-%08d', $value);
    }
}
