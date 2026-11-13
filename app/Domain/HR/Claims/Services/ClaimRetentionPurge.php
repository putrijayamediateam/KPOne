<?php

namespace App\Domain\HR\Claims\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): staff claims are kept 7 years, then
 * deleted with their receipts, actions and payments. Only a closed claim (paid, rejected or withdrawn) goes, and only once
 * its last change is older than 7 years by PostgreSQL's clock, which its delete guard also uses (with a day of margin
 * here). Every real change (a decision, a payment, a reversal) moves the claim's own time; opening a receipt does not, so
 * looking at a claim never restarts the 7 years (Suresh).
 * A claim that a kept claim was copied from ("Send a new claim") waits for that claim. Receipt bytes are deleted after the
 * commit. A payroll line that refunded a claim keeps its frozen amounts; it never pointed at the claim by a foreign key.
 * Claim settings, categories and the number counter are settings and stay.
 */
final class ClaimRetentionPurge
{
    public const RETENTION_YEARS = 7;

    private const CUTOFF = "now() - interval '7 years' - interval '1 day'";

    public function __construct(private AuditRecorder $audit) {}

    /** @return array{claims: int, receipts: int} */
    public function purgeExpired(): array
    {
        $total = ['claims' => 0, 'receipts' => 0];
        $cutoff = self::CUTOFF;
        $organisations = DB::table('hr_staff_claims')->whereIn('status', ['paid', 'rejected', 'withdrawn'])->whereRaw("updated_at < {$cutoff}")
            ->distinct()->pluck('organisation_id')->map(fn ($id): int => (int) $id)->sort()->values();
        foreach ($organisations as $organisationId) {
            try {
                [$counts, $paths] = $this->purgeOrganisation($organisationId);
            } catch (\Throwable $e) {
                // One organisation's failure never stops the others (Suresh); it is tried again the next day.
                Log::warning('HR staff claim retention purge failed for one organisation', ['organisation_id' => $organisationId, 'error' => $e::class]);

                continue;
            }
            // Bytes go only once the rows are gone for good.
            foreach ($paths as $path) {
                Storage::disk(ClaimService::DISK)->delete($path);
            }
            $total['claims'] += $counts['claims'];
            $total['receipts'] += $counts['receipts'];
        }

        return $total;
    }

    /** @return array{0: array{claims: int, receipts: int}, 1: array<int, string>} */
    private function purgeOrganisation(int $organisationId): array
    {
        $cutoff = self::CUTOFF;

        return DB::transaction(function () use ($organisationId, $cutoff): array {
            DB::statement("SELECT set_config('kpone.hr_claim_purge', 'on', true)");
            $ids = DB::table('hr_staff_claims as c')->where('c.organisation_id', $organisationId)->whereIn('c.status', ['paid', 'rejected', 'withdrawn'])
                ->whereRaw("c.updated_at < {$cutoff}")->orderBy('c.id')->lockForUpdate()->pluck('c.id')->map(fn ($id): int => (int) $id)->all();
            // A claim copied from another must go first, and a claim that a kept claim was copied from stays.
            $copies = DB::table('hr_staff_claims')->where('organisation_id', $organisationId)->whereNotNull('copied_from_claim_id')
                ->pluck('copied_from_claim_id', 'id')->map(fn ($from): int => (int) $from)->all();
            $going = array_flip($ids);
            do {
                $before = count($going);
                foreach ($copies as $copy => $from) {
                    if (isset($going[$from]) && ! isset($going[$copy])) {
                        unset($going[$from]);
                    }
                }
            } while (count($going) < $before);
            $ids = array_keys($going);
            rsort($ids); // a copy always has a higher id than its original
            $paths = [];
            foreach ($ids as $id) {
                $paths = [...$paths, ...DB::table('hr_staff_claim_receipts')->where('claim_id', $id)->pluck('stored_path')->map(fn ($p): string => (string) $p)->all()];
                DB::table('hr_staff_claim_receipts')->where('claim_id', $id)->delete();
                DB::table('hr_staff_claim_actions')->where('claim_id', $id)->delete();
                DB::table('hr_staff_claim_payments')->where('claim_id', $id)->where('kind', 'reversal')->delete();
                DB::table('hr_staff_claim_payments')->where('claim_id', $id)->delete();
                DB::table('hr_staff_claims')->where('id', $id)->delete();
            }
            // Switched off again, so even inside an outer transaction nothing after the purge can delete.
            DB::statement("SELECT set_config('kpone.hr_claim_purge', '', true)");
            $counts = ['claims' => count($ids), 'receipts' => count($paths)];
            if ($counts['claims'] > 0) {
                $this->audit->record('hr.claims.retention_purged', null, [...$counts, 'retention_years' => self::RETENTION_YEARS], null, null, $organisationId);
            }

            return [$counts, $paths];
        }, 3);
    }
}
