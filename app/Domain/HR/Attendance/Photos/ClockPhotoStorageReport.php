<?php

namespace App\Domain\HR\Attendance\Photos;

use App\Console\Commands\PurgeAttendanceRecords;
use App\Domain\HR\Attendance\Services\AttendanceRetentionPurge;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\AttendanceSupport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-PHOTO-STORAGE: the "Clock-in photo storage" card on the attendance Settings tab (Director, HR Manager). Counts only:
 * how many photos there are and how much space they take, the monthly rate over the last three full months, a forecast
 * at that rate, and the retention rule with its earliest delete. Never a name, a photo or a per-person figure. Sizes are
 * the recorded photo and thumbnail bytes; the encrypted files on disk take about a third more, and the card says so.
 * Reading it changes nothing; the delete stays with `hr-attendance:purge`.
 */
final class ClockPhotoStorageReport
{
    /** Months of complete data before a forecast is shown (Daniel can change the wording, not the rule). */
    public const FORECAST_MONTHS = 3;

    public function __construct(private AttendanceSettingsService $settings) {}

    /**
     * @return array{photos: int, bytes: int, size: string, oldest: ?string, thisMonth: int, perMonth: ?int, perMonthSize: ?string, forecastMonths: int,
     *     forecastOneYear: ?string, forecastRetention: ?string, retentionYears: int, earliestDelete: ?string, draft: bool}
     */
    public function card(int $organisationId): array
    {
        $today = $this->settings->today($organisationId);
        $zone = $today->getTimezone()->getName();
        $base = fn () => DB::table('hr_clock_photos')->where('organisation_id', $organisationId);
        $totals = $base()->selectRaw('count(*) as n, coalesce(sum(bytes + thumb_bytes), 0) as b, min(created_at) as oldest')->first();
        $photos = (int) ($totals->n ?? 0);
        $bytes = (int) ($totals->b ?? 0);
        $oldest = $totals !== null && $totals->oldest !== null ? CarbonImmutable::parse((string) $totals->oldest)->setTimezone($zone) : null;

        $monthStart = $today->startOfMonth();
        $thisMonth = $base()->where('created_at', '>=', $monthStart->utc())->count();
        // The last three FULL months, and only once photos go back that far: a forecast from a part month would overstate.
        $windowStart = $monthStart->subMonths(self::FORECAST_MONTHS);
        $perMonth = null;
        $perMonthBytes = null;
        if ($oldest !== null && $oldest <= $windowStart) {
            $window = $base()->where('created_at', '>=', $windowStart->utc())->where('created_at', '<', $monthStart->utc())
                ->selectRaw('count(*) as n, coalesce(sum(bytes + thumb_bytes), 0) as b')->first();
            $perMonth = (int) round(((int) ($window->n ?? 0)) / self::FORECAST_MONTHS);
            $perMonthBytes = (int) round(((int) ($window->b ?? 0)) / self::FORECAST_MONTHS);
        }
        $years = AttendanceRetentionPurge::RETENTION_YEARS;
        // The first photo delete: the oldest photo goes 7 years and a day after it at the earliest (later if something newer
        // still hangs on its attendance record).
        $earliest = $oldest?->addYears($years)->addDay();

        return [
            'photos' => $photos,
            'bytes' => $bytes,
            'size' => PurgeAttendanceRecords::size($bytes),
            'oldest' => $oldest === null ? null : AttendanceSupport::day($oldest),
            'thisMonth' => $thisMonth,
            'perMonth' => $perMonth,
            'perMonthSize' => $perMonthBytes === null ? null : PurgeAttendanceRecords::size($perMonthBytes),
            'forecastMonths' => self::FORECAST_MONTHS,
            // At the current rate, on top of what is stored now; at the retention mark deletes start, so it stops growing.
            // Never more than the steady size at the retention mark: once deletes start, older photos go as new ones come (Suresh m3).
            'forecastOneYear' => $perMonthBytes === null ? null : PurgeAttendanceRecords::size(min($bytes + 12 * $perMonthBytes, 12 * $years * $perMonthBytes)),
            'forecastRetention' => $perMonthBytes === null ? null : PurgeAttendanceRecords::size(12 * $years * $perMonthBytes),
            'retentionYears' => $years,
            'earliestDelete' => $earliest === null ? null : AttendanceSupport::day($earliest),
            // The 7 years is the owner's draft, to confirm (HR-3a 2026-11-11, HR-PHOTO 2026-11-12).
            'draft' => true,
        ];
    }
}
