<?php

namespace App\Domain\HR\Credentials\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): credential records are kept 7 years,
 * then deleted. Two cases, both only for a record whose last change is older than 7 years by PostgreSQL's clock, which its
 * delete guard also uses (with a day of margin here):
 *  - a record that is no longer in force (replaced, rejected, or left waiting), unless it is the newest version of that
 *    person's credential type, which stays so the version numbers carry on without gaps;
 *  - every record of a person whose latest employment period ended more than 7 years ago (and who has no open one).
 * An accepted record of a current staff member is never deleted. Evidence certificates are staff documents and follow the
 * staff document retention; credential types are settings and stay.
 */
final class CredentialRetentionPurge
{
    public const RETENTION_YEARS = 7;

    private const CUTOFF = "now() - interval '7 years' - interval '1 day'";

    public function __construct(private AuditRecorder $audit) {}

    public function purgeExpired(): int
    {
        $total = 0;
        $cutoff = self::CUTOFF;
        $organisations = DB::table('hr_credential_records')->whereRaw("updated_at < {$cutoff}")->distinct()->pluck('organisation_id')
            ->map(fn ($id): int => (int) $id)->sort()->values();
        foreach ($organisations as $organisationId) {
            try {
                $count = $this->purgeOrganisation($organisationId);
            } catch (\Throwable $e) {
                // One organisation's failure never stops the others (Suresh); it is tried again the next day.
                Log::warning('HR credential retention purge failed for one organisation', ['organisation_id' => $organisationId, 'error' => $e::class]);

                continue;
            }
            $total += $count;
        }

        return $total;
    }

    private function purgeOrganisation(int $organisationId): int
    {
        $cutoff = self::CUTOFF;

        return DB::transaction(function () use ($organisationId, $cutoff): int {
            DB::statement("SELECT set_config('kpone.hr_credential_purge', 'on', true)");
            // Left more than 7 years ago: the latest period ended before the cutoff and none is open.
            $left = fn ($q) => $q->select('p.worker_id')->from('hr_employment_periods as p')->where('p.organisation_id', $organisationId)
                ->groupBy('p.worker_id')->havingRaw('bool_and(p.ends_on IS NOT NULL)')
                ->havingRaw("max(p.ends_on) < (now() AT TIME ZONE 'UTC')::date - interval '7 years' - interval '1 day'");
            $ids = DB::table('hr_credential_records as r')->where('r.organisation_id', $organisationId)->whereRaw("r.updated_at < {$cutoff}")
                ->where(fn ($q) => $q->whereIn('r.worker_id', $left)
                    ->orWhere(fn ($q) => $q->whereIn('r.status', ['replaced', 'rejected', 'waiting'])
                        ->whereExists(fn ($q) => $q->from('hr_credential_records as n')->whereColumn('n.worker_id', 'r.worker_id')
                            ->whereColumn('n.type_id', 'r.type_id')->whereColumn('n.version', '>', 'r.version'))))
                ->orderBy('r.id')->lockForUpdate()->pluck('r.id')->all();
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table('hr_credential_records')->whereIn('id', $chunk)->delete();
            }
            // Switched off again, so even inside an outer transaction nothing after the purge can delete.
            DB::statement("SELECT set_config('kpone.hr_credential_purge', '', true)");
            if ($count > 0) {
                $this->audit->record('hr.credentials.retention_purged', null, ['records' => $count, 'retention_years' => self::RETENTION_YEARS], null, null, $organisationId);
            }

            return $count;
        }, 3);
    }
}
