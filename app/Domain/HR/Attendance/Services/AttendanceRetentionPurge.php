<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Retention (owner, 2026-11-11; the accountant confirms the 7 years before real use): attendance records are kept 7
 * years, then deleted. A clock session goes with its events, corrections, overtime requests, location result and its
 * HR-PHOTO photos (whose encrypted files are deleted after the commit), and
 * only once every one of those is itself past the 7 years (a later correction or overtime ask keeps the session until it
 * is old enough too). Corrections with no session, and location-check consents, go on their own time. Settings versions
 * are kept. PostgreSQL refuses each delete unless this purge marks its transaction and the row is past the 7 years by
 * its own clock, which is also the clock used here (with a day of margin).
 */
final class AttendanceRetentionPurge
{
    public const RETENTION_YEARS = 7;

    private const CUTOFF = "now() - interval '7 years' - interval '1 day'";

    public function __construct(private AuditRecorder $audit) {}

    /** @return array{sessions: int, corrections: int, consents: int} */
    public function purgeExpired(): array
    {
        $total = ['sessions' => 0, 'corrections' => 0, 'consents' => 0];
        $cutoff = self::CUTOFF;
        $organisations = DB::table('hr_clock_sessions')->whereRaw("clock_in_at < {$cutoff}")->distinct()->pluck('organisation_id')
            ->merge(DB::table('hr_attendance_corrections')->whereNull('clock_session_id')->whereNull('created_session_id')->whereRaw("created_at < {$cutoff}")->distinct()->pluck('organisation_id'))
            ->merge(DB::table('hr_location_consents')->whereRaw("acknowledged_at < {$cutoff}")->distinct()->pluck('organisation_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values();
        foreach ($organisations as $organisationId) {
            $paths = [];
            $counts = DB::transaction(function () use ($organisationId, $cutoff, &$paths): array {
                $paths = [];
                DB::statement("SELECT set_config('kpone.hr_attendance_purge', 'on', true)");
                DB::statement("SELECT set_config('kpone.hr_location_purge', 'on', true)");
                $sessions = DB::table('hr_clock_sessions as s')->where('s.organisation_id', $organisationId)->whereRaw("s.clock_in_at < {$cutoff}")
                    ->whereNotExists(fn ($q) => $q->from('hr_attendance_corrections as c')->where(fn ($q) => $q->whereColumn('c.clock_session_id', 's.id')
                        ->orWhereColumn('c.created_session_id', 's.id'))->whereRaw("c.created_at >= {$cutoff}"))
                    ->whereNotExists(fn ($q) => $q->from('hr_overtime_requests as o')->whereColumn('o.clock_session_id', 's.id')->whereRaw("o.created_at >= {$cutoff}"))
                    ->whereNotExists(fn ($q) => $q->from('hr_clock_events as e')->whereColumn('e.clock_session_id', 's.id')->whereRaw("e.created_at >= {$cutoff}"))
                    ->whereNotExists(fn ($q) => $q->from('hr_clock_photos as p')->whereColumn('p.clock_session_id', 's.id')->whereRaw("p.created_at >= {$cutoff}"))
                    // A correction of this session with an event on another session would block the correction's delete (a
                    // foreign key) and roll the whole purge back every day; such a session waits (Suresh, m1).
                    ->whereNotExists(fn ($q) => $q->from('hr_clock_events as e2')->join('hr_attendance_corrections as c2', 'c2.id', '=', 'e2.correction_id')
                        ->where(fn ($q) => $q->whereColumn('c2.clock_session_id', 's.id')->orWhereColumn('c2.created_session_id', 's.id'))
                        ->whereColumn('e2.clock_session_id', '<>', 's.id'))
                    ->orderBy('s.id')->lockForUpdate()->pluck('s.id')->map(fn ($id): int => (int) $id)->all();
                $corrections = 0;
                foreach (array_chunk($sessions, 500) as $ids) {
                    foreach (DB::table('hr_clock_photos')->whereIn('clock_session_id', $ids)->get(['stored_path', 'thumb_path']) as $photo) {
                        array_push($paths, (string) $photo->stored_path, (string) $photo->thumb_path);
                    }
                    // A session's photos go with it (counted as part of the session, as its events are).
                    DB::table('hr_clock_photos')->whereIn('clock_session_id', $ids)->delete();
                    DB::table('hr_clock_location_checks')->whereIn('clock_session_id', $ids)->delete();
                    DB::table('hr_overtime_requests')->whereIn('clock_session_id', $ids)->delete();
                    DB::table('hr_clock_events')->whereIn('clock_session_id', $ids)->delete();
                    $corrections += DB::table('hr_attendance_corrections')->where(fn ($q) => $q->whereIn('clock_session_id', $ids)->orWhereIn('created_session_id', $ids))->delete();
                    DB::table('hr_clock_sessions')->whereIn('id', $ids)->delete();
                }
                $corrections += DB::table('hr_attendance_corrections as c')->where('c.organisation_id', $organisationId)->whereNull('c.clock_session_id')
                    ->whereNull('c.created_session_id')->whereRaw("c.created_at < {$cutoff}")
                    ->whereNotExists(fn ($q) => $q->from('hr_clock_events as e')->whereColumn('e.correction_id', 'c.id'))->delete();
                $consents = DB::table('hr_location_consents')->where('organisation_id', $organisationId)->whereRaw("acknowledged_at < {$cutoff}")->delete();
                // Switched off again, so even inside an outer transaction nothing after the purge can delete (Suresh).
                DB::statement("SELECT set_config('kpone.hr_attendance_purge', '', true), set_config('kpone.hr_location_purge', '', true)");
                $counts = ['sessions' => count($sessions), 'corrections' => $corrections, 'consents' => $consents];
                if (array_sum($counts) > 0) {
                    $this->audit->record('hr.attendance.retention_purged', null, [...$counts, 'retention_years' => self::RETENTION_YEARS], null, null, $organisationId);
                }

                return $counts;
            }, 3);
            // The rows are gone with the commit; now the encrypted files. A file that fails to delete is left for the
            // `hr-clock-photos:purge` backstop, which removes any file no row points at.
            foreach ($paths as $path) {
                if (! Storage::disk(ClockPhotoService::DISK)->delete($path)) {
                    Log::warning('HR clock photo file could not be deleted after the attendance purge', ['organisation_id' => $organisationId]);
                }
            }
            foreach ($counts as $k => $v) {
                $total[$k] += $v;
            }
        }

        return $total;
    }
}
