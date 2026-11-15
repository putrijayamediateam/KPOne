<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PDPA 15 (owner, 2026-11-11, relayed by B.E.R.N.I.S.S): 6 months after an application closed unsuccessfully (Failed,
 * Not taken forward, Auto-rejected or Withdrawn), the candidate's interview records are deleted: their time choices,
 * their interview bookings and their interview-document download records. The application row, its stage history and the
 * list of messages sent stay (the application purge clears the rest on its own retention). A Failed candidate still in
 * the talent pool keeps them until the pool period ends. The clinic's own interview documents are not personal data and
 * stay until HR removes them. PostgreSQL refuses each delete unless this purge marked its transaction and the
 * application closed unsuccessfully more than 6 months ago by its own clock, which is also the clock used here.
 */
final class InterviewRecordsPurge
{
    public const RETENTION_MONTHS = 6;

    public const UNSUCCESSFUL = ['failed', 'not_taken_forward', 'auto_rejected', 'withdrawn'];

    public function __construct(private AuditRecorder $audit) {}

    /** @return array{applications: int, picks: int, interviews: int, downloads: int} */
    public function purgeExpired(): array
    {
        $total = ['applications' => 0, 'picks' => 0, 'interviews' => 0, 'downloads' => 0];
        $organisations = DB::table('hr_job_applications')->whereIn('status', self::UNSUCCESSFUL)->whereRaw("closed_at < now() - interval '6 months'")
            ->distinct()->pluck('organisation_id')->map(fn ($id): int => (int) $id)->sort()->values();
        foreach ($organisations as $organisationId) {
            try {
                $counts = $this->purgeOrganisation($organisationId);
            } catch (\Throwable $e) {
                // One organisation's failure never stops the others; it is tried again the next day.
                Log::warning('HR interview records purge failed for one organisation', ['organisation_id' => $organisationId, 'error' => $e::class]);

                continue;
            }
            foreach ($counts as $k => $v) {
                $total[$k] += $v;
            }
        }

        return $total;
    }

    /** @return array{applications: int, picks: int, interviews: int, downloads: int} */
    private function purgeOrganisation(int $organisationId): array
    {
        return DB::transaction(function () use ($organisationId): array {
            $applications = DB::table('hr_job_applications')->where('organisation_id', $organisationId)->whereIn('status', self::UNSUCCESSFUL)
                ->whereRaw("closed_at < now() - interval '6 months'")
                ->where(fn ($q) => $q->whereExists(fn ($q) => $q->from('hr_job_interview_picks as p')->whereColumn('p.application_id', 'hr_job_applications.id'))
                    ->orWhereExists(fn ($q) => $q->from('hr_job_interviews as i')->whereColumn('i.application_id', 'hr_job_applications.id'))
                    ->orWhereExists(fn ($q) => $q->from('hr_interview_document_events as e')->whereColumn('e.application_id', 'hr_job_applications.id')))
                ->orderBy('id')->lockForUpdate()->get();
            // A Failed candidate in the talent pool keeps their records until the pool period is over.
            $ids = $applications->filter(fn (\stdClass $a): bool => TalentPool::until($a) === null)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $counts = ['applications' => count($ids), 'picks' => 0, 'interviews' => 0, 'downloads' => 0];
            if ($ids === []) {
                return $counts;
            }
            DB::statement("SELECT set_config('kpone.hr_interview_purge', 'on', true)");
            foreach (array_chunk($ids, 500) as $chunk) {
                $counts['interviews'] += DB::table('hr_job_interviews')->whereIn('application_id', $chunk)->delete();
                $counts['picks'] += DB::table('hr_job_interview_picks')->whereIn('application_id', $chunk)->delete();
                $counts['downloads'] += DB::table('hr_interview_document_events')->whereIn('application_id', $chunk)->where('action', 'downloaded')->delete();
            }
            // Switched off again, so even inside an outer transaction nothing after the purge can delete.
            DB::statement("SELECT set_config('kpone.hr_interview_purge', '', true)");
            $this->audit->record('hr.applications.interview_records_purged', null, [...$counts, 'retention_months' => self::RETENTION_MONTHS], null, null, $organisationId);

            return $counts;
        }, 3);
    }
}
