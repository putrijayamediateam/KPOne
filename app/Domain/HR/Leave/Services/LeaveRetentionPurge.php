<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): leave records are kept 7 years after
 * their leave year, then deleted. A leave year Y goes once the year is Y + 8 or later by PostgreSQL's clock, the clock
 * its delete guard also uses; the UTC year turns about 8 hours after Kuala Lumpur's, so the purge can only run late, never
 * early. For each organisation, in one transaction: the year's requests (an 8-year-old pending one is abandoned and has no
 * ledger line, so it goes too; Suresh) with their actions, evidence files and file events, the year's openings and
 * adjustments, every ledger line and entitlement of the year, and retry records older than 7 years. One that a ledger
 * line of a later year still points at waits. Evidence file bytes still on disk are deleted after the commit, and the
 * leave retention wins over a longer evidence retention set on a leave type. Leave types, working days and public
 * holidays are settings and stay.
 */
final class LeaveRetentionPurge
{
    public const RETENTION_YEARS = 7;

    /** A leave type keeps evidence files at most 7 years (owner, 2026-11-11: "cap at 7 years"); the leave records go then anyway. */
    public const MAX_EVIDENCE_DAYS = 2555;

    private const OLD_YEAR = "year < extract(year FROM now() AT TIME ZONE 'UTC') - 7";

    public function __construct(private AuditRecorder $audit) {}

    /** @return array{requests: int, entries: int, ledger: int, entitlements: int, files: int} */
    public function purgeExpired(): array
    {
        $total = ['requests' => 0, 'entries' => 0, 'ledger' => 0, 'entitlements' => 0, 'files' => 0];
        $old = self::OLD_YEAR;
        $organisations = collect(['hr_leave_requests', 'hr_leave_openings', 'hr_leave_adjustments', 'hr_leave_ledger', 'hr_leave_entitlements'])
            ->flatMap(fn (string $table) => DB::table($table)->whereRaw($old)->distinct()->pluck('organisation_id'))
            ->merge(DB::table('hr_leave_retries')->whereRaw("created_at < now() - interval '7 years'")->distinct()->pluck('organisation_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        foreach ($organisations as $organisationId) {
            try {
                [$counts, $paths] = $this->purgeOrganisation($organisationId);
            } catch (\Throwable $e) {
                // One organisation's failure never stops the others (Suresh); it is tried again the next day.
                Log::warning('HR leave retention purge failed for one organisation', ['organisation_id' => $organisationId, 'error' => $e::class]);

                continue;
            }
            // Bytes go only once the rows are gone for good.
            foreach ($paths as $path) {
                Storage::disk(LeaveEvidenceService::DISK)->delete($path);
            }
            foreach ($counts as $k => $v) {
                $total[$k] += $v;
            }
        }

        return $total;
    }

    /** @return array{0: array{requests: int, entries: int, ledger: int, entitlements: int, files: int}, 1: array<int, string>} */
    private function purgeOrganisation(int $organisationId): array
    {
        $old = self::OLD_YEAR;

        return DB::transaction(function () use ($organisationId, $old): array {
            DB::statement("SELECT set_config('kpone.hr_leave_purge', 'on', true)");
            // None that a ledger line of a kept year still points at (that line would block the
            // delete by its foreign key and roll the whole purge back every day).
            $kept = fn (string $column) => fn ($q) => $q->from('hr_leave_ledger as l')->whereColumn("l.{$column}", 'x.id')->whereRaw("NOT (l.{$old})");
            $requests = DB::table('hr_leave_requests as x')->where('x.organisation_id', $organisationId)->whereRaw("x.{$old}")
                ->whereNotExists($kept('leave_request_id'))
                ->orderBy('x.id')->lockForUpdate()->pluck('x.id')->map(fn ($id): int => (int) $id)->all();
            $entries = 0;
            $paths = [];
            foreach (['hr_leave_openings' => 'leave_opening_id', 'hr_leave_adjustments' => 'leave_adjustment_id'] as $table => $column) {
                $ids = DB::table("{$table} as x")->where('x.organisation_id', $organisationId)->whereRaw("x.{$old}")->whereNotExists($kept($column))->orderBy('x.id')->lockForUpdate()->pluck('x.id')->all();
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('hr_leave_ledger')->whereIn($column, $chunk)->delete();
                    $entries += DB::table($table)->whereIn('id', $chunk)->delete();
                }
            }
            foreach (array_chunk($requests, 500) as $ids) {
                $files = DB::table('hr_leave_evidence_files')->whereIn('leave_request_id', $ids)->get(['id', 'status', 'stored_path']);
                $paths = [...$paths, ...$files->where('status', 'active')->pluck('stored_path')->map(fn ($p): string => (string) $p)->all()];
                DB::table('hr_leave_evidence_events')->whereIn('evidence_file_id', $files->pluck('id'))->delete();
                DB::table('hr_leave_evidence_files')->whereIn('id', $files->pluck('id'))->delete();
                DB::table('hr_leave_ledger')->whereIn('leave_request_id', $ids)->delete();
                DB::table('hr_leave_request_actions')->whereIn('leave_request_id', $ids)->delete();
                DB::table('hr_leave_requests')->whereIn('id', $ids)->delete();
            }
            // The rest of the old years' ledger (grants, carry-forward, expiry) and entitlements.
            $ledger = DB::table('hr_leave_ledger')->where('organisation_id', $organisationId)->whereRaw($old)
                ->whereNull('leave_request_id')->whereNull('leave_opening_id')->whereNull('leave_adjustment_id')->delete();
            $entitlements = DB::table('hr_leave_entitlements')->where('organisation_id', $organisationId)->whereRaw($old)->delete();
            DB::table('hr_leave_retries')->where('organisation_id', $organisationId)->whereRaw("created_at < now() - interval '7 years'")->delete();
            // Switched off again, so even inside an outer transaction nothing after the purge can delete.
            DB::statement("SELECT set_config('kpone.hr_leave_purge', '', true)");
            $counts = ['requests' => count($requests), 'entries' => $entries, 'ledger' => $ledger, 'entitlements' => $entitlements, 'files' => count($paths)];
            if ($counts['requests'] + $counts['entries'] + $counts['ledger'] + $counts['entitlements'] > 0) {
                $this->audit->record('hr.leave.retention_purged', null, [...$counts, 'retention_years' => self::RETENTION_YEARS], null, null, $organisationId);
            }

            return [$counts, $paths];
        }, 3);
    }
}
