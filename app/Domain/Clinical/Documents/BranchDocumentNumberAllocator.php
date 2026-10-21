<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchDocumentNumberAllocator
{
    public function allocate(Branch $branch, string $type, string $documentPrefix, CarbonImmutable $issuedAt): string
    {
        if ($type !== 'referral' || $documentPrefix !== 'REF') {
            throw new \InvalidArgumentException('Unsupported document counter.');
        }
        $prefix = ['CHERAS' => 'CHR', 'SUNGAI_BESI' => 'SGB', 'PUCHONG' => 'PCG'][$branch->code] ?? null;
        if ($branch->organisation->code !== 'KLINIK_PUTRIJAYA' || $prefix === null) {
            throw ValidationException::withMessages(['referral' => 'Referral numbering is not configured for this branch.']);
        }
        $year = (int) $issuedAt->setTimezone($branch->timezone)->format('Y');
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["ref-number:{$branch->organisation_id}:{$branch->id}:{$year}"]);
        $counter = DB::table('referral_number_counters')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('year', $year)->lockForUpdate()->first();
        $next = ($counter->last_number ?? 0) + 1;
        if ($next > 999999) {
            throw ValidationException::withMessages(['referral' => 'The annual referral number range is exhausted.']);
        }
        $counter ? DB::table('referral_number_counters')->where('id', $counter->id)->update(['last_number' => $next]) : DB::table('referral_number_counters')->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'year' => $year, 'last_number' => $next]);

        return sprintf('%s/KP/%s/%d/%06d', $documentPrefix, $prefix, $year, $next);
    }
}
