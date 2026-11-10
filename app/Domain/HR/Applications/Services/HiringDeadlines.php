<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HR-17b auto-reject (HR-PLAN 19.3; owner, 2026-11-10: "Both stages", 3 working days each). An application that waits
 * at New or Phone screening counts the working days of its branch (the position's branch, or the first active branch
 * when the position is for any branch) after the day it entered the stage: the HR-2a working days less that branch's
 * public holidays. On the third working day one reminder goes to the hiring mailbox (and shows in "Needs you"); from
 * the fourth the daily check closes it as Auto-rejected and tells the candidate. A branch with no working days set
 * never auto-rejects, and "Needs you" says so.
 */
final class HiringDeadlines
{
    public const WAITING = ['new', 'phone_screening'];

    /** How far ahead the count may look, so a branch with very few working days still ends. */
    private const HORIZON_DAYS = 90;

    /** @var array<int, array{zone:string, weekdays:?list<int>}> */
    private array $branches = [];

    public function __construct(private HiringMessenger $messenger, private AuditRecorder $audit) {}

    /**
     * The local date of the reminder and of the auto-reject for an application, or null when its branch has no working
     * days set. Both are branch-local dates.
     *
     * @return array{remind:string, reject:string}|null
     */
    public function deadlines(\stdClass $application): ?array
    {
        $branch = $this->branchFor($application);
        if ($branch === null || $branch['weekdays'] === null || $branch['weekdays'] === []) {
            return null;
        }
        $days = (int) config('hr-applications.auto_reject_working_days', 3);
        $entered = CarbonImmutable::parse($application->stage_entered_at)->setTimezone($branch['zone'])->startOfDay();
        $holidays = DB::table('hr_public_holidays')->where('branch_id', $branch['id'])
            ->whereBetween('holiday_date', [$entered->toDateString(), $entered->addDays(self::HORIZON_DAYS)->toDateString()])
            ->pluck('holiday_date')->map(fn ($d): string => substr((string) $d, 0, 10))->flip()->all();
        $found = [];
        for ($i = 1; $i <= self::HORIZON_DAYS && count($found) < $days + 1; $i++) {
            $day = $entered->addDays($i);
            if (in_array($day->isoWeekday(), $branch['weekdays'], true) && ! isset($holidays[$day->toDateString()])) {
                $found[] = $day->toDateString();
            }
        }
        if (count($found) < $days + 1) {
            return null;
        }

        return ['remind' => $found[$days - 1], 'reject' => $found[$days]];
    }

    /** The branch's local date today for an application. */
    public function today(\stdClass $application): ?string
    {
        $branch = $this->branchFor($application);

        return $branch === null ? null : CarbonImmutable::now($branch['zone'])->toDateString();
    }

    /**
     * The daily check: reminds and auto-rejects. Returns [reminders, auto-rejects].
     *
     * @return array{0:int, 1:int}
     */
    public function run(): array
    {
        $reminded = 0;
        $rejected = 0;
        DB::table('hr_job_applications')->whereIn('status', self::WAITING)->whereNull('purged_at')->select('id')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$reminded, &$rejected): void {
                foreach ($chunk as $candidate) {
                    try {
                        $outcome = $this->check((int) $candidate->id);
                    } catch (\Throwable $e) {
                        Log::warning('Hiring auto-reject check failed for one application', ['application_id' => $candidate->id, 'error' => $e::class]);

                        continue;
                    }
                    $reminded += $outcome === 'reminded' ? 1 : 0;
                    $rejected += $outcome === 'rejected' ? 1 : 0;
                }
            });

        return [$reminded, $rejected];
    }

    /** One application, under its row lock: nothing, a reminder, or an auto-reject. */
    public function check(int $applicationId): ?string
    {
        return DB::transaction(function () use ($applicationId): ?string {
            $row = DB::table('hr_job_applications')->where('id', $applicationId)->lockForUpdate()->first();
            if ($row === null || $row->purged_at !== null || ! in_array($row->status, self::WAITING, true)) {
                return null;
            }
            $deadlines = $this->deadlines($row);
            $today = $this->today($row);
            if ($deadlines === null || $today === null || $today < $deadlines['remind']) {
                return null;
            }
            if ($today >= $deadlines['reject']) {
                $now = now()->utc();
                DB::table('hr_job_applications')->where('id', $row->id)->update(['status' => 'auto_rejected', 'closed_at' => $now, 'stage_entered_at' => $now,
                    'lock_version' => (int) $row->lock_version + 1, 'updated_at' => $now]);
                DB::table('hr_job_application_events')->insert([
                    ['organisation_id' => $row->organisation_id, 'application_id' => $row->id, 'action' => 'auto_rejected', 'from_status' => null,
                        'to_status' => null, 'actor_user_id' => null, 'created_at' => $now],
                    ['organisation_id' => $row->organisation_id, 'application_id' => $row->id, 'action' => 'status_changed', 'from_status' => $row->status,
                        'to_status' => 'auto_rejected', 'actor_user_id' => null, 'created_at' => $now],
                ]);
                $this->audit->record('hr.applications.auto_rejected', null, ['application_id' => (int) $row->id, 'from' => $row->status], null, null, (int) $row->organisation_id);
                $this->messenger->record($row, 'auto_rejected', 'auto_rejected');

                return 'rejected';
            }
            $before = DB::table('hr_job_application_messages')->where('application_id', $row->id)->where('template', 'auto_reject_reminder')->where('stage', $row->status)->exists();
            if ($before) {
                return null;
            }
            $this->messenger->record($row, 'auto_reject_reminder', (string) $row->status, null, ['reference' => HiringMessenger::reference((string) $row->public_id),
                'stage' => JobApplicationService::STATUSES[$row->status]]);

            return 'reminded';
        }, 3);
    }

    /** @return array{id:int, zone:string, weekdays:?list<int>}|null */
    private function branchFor(\stdClass $application): ?array
    {
        $branchId = DB::table('hr_job_positions')->where('id', $application->position_id)->value('branch_id');
        $branchId ??= DB::table('branches')->where('organisation_id', $application->organisation_id)->where('is_active', true)->orderBy('id')->value('id');
        if ($branchId === null) {
            return null;
        }
        $branchId = (int) $branchId;
        if (! isset($this->branches[$branchId])) {
            $zone = DB::table('branches')->where('id', $branchId)->value('timezone');
            $json = DB::table('hr_branch_working_days')->where('branch_id', $branchId)->value('weekdays');
            $days = is_string($json) ? json_decode($json, true) : null;
            $this->branches[$branchId] = ['zone' => is_string($zone) ? $zone : 'UTC', 'weekdays' => is_array($days) ? array_values(array_map('intval', $days)) : null];
        }

        return ['id' => $branchId, ...$this->branches[$branchId]];
    }
}
