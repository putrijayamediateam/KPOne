<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchDocumentNumberAllocator
{
    private const TYPES = ['referral' => ['REF', 'referral_number_counters', 'ref'], 'attendance' => ['ATT', 'attendance_number_counters', 'att']];

    public function allocate(Branch $branch, string $type, string $documentPrefix, CarbonImmutable $issuedAt): string
    {
        if (! isset(self::TYPES[$type]) || self::TYPES[$type][0] !== $documentPrefix) {
            throw new \InvalidArgumentException('Unsupported document counter.');
        }
        $prefix = ['CHERAS' => 'CHR', 'SUNGAI_BESI' => 'SGB', 'PUCHONG' => 'PCG'][$branch->code] ?? null;
        if ($branch->organisation->code !== 'KLINIK_PUTRIJAYA' || $prefix === null) {
            throw ValidationException::withMessages([$type => ucfirst($type).' numbering is not configured for this branch.']);
        }
        $year = (int) $issuedAt->setTimezone($branch->timezone)->format('Y');
        [, $table, $namespace] = self::TYPES[$type];
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$namespace}-number:{$branch->organisation_id}:{$branch->id}:{$year}"]);
        $counter = DB::table($table)->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('year', $year)->lockForUpdate()->first();
        $next = ($counter->last_number ?? 0) + 1;
        if ($next > 999999) {
            throw ValidationException::withMessages([$type => 'The annual '.$type.' number range is exhausted.']);
        }
        $counter ? DB::table($table)->where('id', $counter->id)->update(['last_number' => $next]) : DB::table($table)->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'year' => $year, 'last_number' => $next]);

        return sprintf('%s/KP/%s/%d/%06d', $documentPrefix, $prefix, $year, $next);
    }
}
