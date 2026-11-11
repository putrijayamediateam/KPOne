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
 * the fourth the daily check closes it as Auto-rejected and tells the candidate. Nothing is closed before its reminder:
 * when the reminder was missed (the scheduler was down), it goes first and the closing waits for the next working day
 * (Suresh, HR-17b). Both happen from 08:00 branch time, so nobody gets mail at midnight. A branch with no working days
 * set never auto-rejects, and "Needs you" says so.
 */
final class HiringDeadlines
{
    public const WAITING = ['new', 'phone_screening'];

    /**
     * HR-17c (owner, 2026-11-11): an interview invitation the candidate leaves unanswered is auto-rejected the same way,
     * counted from the invitation or from HR's latest "ask for new times", and only while the current round has no
     * picks (once the candidate has picked, it waits for HR, and PostgreSQL refuses the closing too).
     */
    public const AWAITING_PICKS = 'scheduling';

    /** The branch-local hour from which reminders and closings are sent. */
    public const FROM_HOUR = 8;

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
        $entered = CarbonImmutable::parse(self::countStart($application) ?? $application->stage_entered_at)->setTimezone($branch['zone'])->startOfDay();
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

    /**
     * When the current count started: the later of the stage start (the invitation, a cancelled interview, or the move to
     * New or Phone screening), HR's latest "ask for new times" at Scheduling, and HR's latest "give 3 more working days"
     * (follow-up b). Null means the stage start itself.
     */
    /**
     * How many times HR gave this stage (at Scheduling, this round) more time: the HR reminder comes once per count, so
     * an application never closes without a reminder of its own (Suresh M1).
     */
    public static function extensions(\stdClass $application): int
    {
        $start = (string) $application->stage_entered_at;
        if ($application->status === self::AWAITING_PICKS) {
            $asked = DB::table('hr_job_application_events')->where('application_id', $application->id)->where('action', 'picks_declined')->max('created_at');
            $start = $asked !== null && CarbonImmutable::parse((string) $asked)->gt(CarbonImmutable::parse($start)) ? (string) $asked : $start;
        }

        return DB::table('hr_job_application_events')->where('application_id', $application->id)->where('action', 'deadline_extended')
            ->where('created_at', '>', CarbonImmutable::parse($start)->utc())->count();
    }

    public static function countStart(\stdClass $application): ?string
    {
        $actions = $application->status === self::AWAITING_PICKS ? ['picks_declined', 'deadline_extended'] : ['deadline_extended'];
        $latest = DB::table('hr_job_application_events')->where('application_id', $application->id)->whereIn('action', $actions)->max('created_at');

        return $latest !== null && CarbonImmutable::parse((string) $latest)->gt(CarbonImmutable::parse($application->stage_entered_at)) ? (string) $latest : null;
    }

    /**
     * Owner, 2026-11-11 (#484 follow-up): the last day the candidate may still choose times, shown on their page. It is the
     * day of the HR reminder; the application closes from the next working day. Null when nothing will close it (no
     * working days set), the candidate has already picked this round, or the day has passed.
     */
    public function pickBy(\stdClass $application): ?string
    {
        if ($application->status !== self::AWAITING_PICKS || DB::table('hr_job_interview_picks')->where('application_id', $application->id)
            ->where('round', $application->interview_round)->exists()) {
            return null;
        }

        $by = $this->deadlines($application)['remind'] ?? null;
        $today = $this->today($application);

        // Once the day has passed the line goes (Suresh m3): picking stays open until the daily check closes it.
        return $by === null || ($today !== null && $today > $by) ? null : $by;
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
        DB::table('hr_job_applications')->whereIn('status', [...self::WAITING, self::AWAITING_PICKS])->whereNull('purged_at')->select('id')->orderBy('id')
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
            if ($row === null || $row->purged_at !== null || ! in_array($row->status, [...self::WAITING, self::AWAITING_PICKS], true)) {
                return null;
            }
            $round = 0;
            if ($row->status === self::AWAITING_PICKS) {
                $round = (int) $row->interview_round;
                if (DB::table('hr_job_interview_picks')->where('application_id', $row->id)->where('round', $round)->exists()) {
                    return null; // the candidate has picked: HR's turn
                }
            }
            $deadlines = $this->deadlines($row);
            $branch = $this->branchFor($row);
            if ($deadlines === null || $branch === null) {
                return null;
            }
            $now = CarbonImmutable::now($branch['zone']);
            $today = $now->toDateString();
            if ($today < $deadlines['remind'] || $now->hour < self::FROM_HOUR) {
                return null;
            }
            $template = $row->status === self::AWAITING_PICKS ? 'pick_reminder' : 'auto_reject_reminder';
            $extension = self::extensions($row);
            $reminder = DB::table('hr_job_application_messages')->where('application_id', $row->id)->where('template', $template)
                ->where('stage', $row->status)->where('round', $round)->where('extension', $extension)->value('created_at');
            $remindedOn = $reminder === null ? null : CarbonImmutable::parse($reminder)->setTimezone($branch['zone'])->toDateString();
            // Closed only once the reminder has had a working day of its own.
            if ($today >= $deadlines['reject'] && $remindedOn !== null && $remindedOn < $today && $this->workingDayBetween($row, $remindedOn, $today)) {
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
            if ($reminder !== null) {
                return null;
            }
            $params = ['reference' => HiringMessenger::reference((string) $row->public_id)];
            if ($template === 'auto_reject_reminder') {
                $params['stage'] = JobApplicationService::STATUSES[$row->status];
            }
            $this->messenger->record($row, $template, (string) $row->status, null, $params, $round, $extension);

            return 'reminded';
        }, 3);
    }

    /** Whether $to is a working day after $from at the application's branch (so a late reminder still gets its day). */
    private function workingDayBetween(\stdClass $application, string $from, string $to): bool
    {
        $branch = $this->branchFor($application);
        if ($branch === null || $branch['weekdays'] === null) {
            return false;
        }
        $day = CarbonImmutable::parse($to);
        $holiday = DB::table('hr_public_holidays')->where('branch_id', $branch['id'])->whereDate('holiday_date', $day->toDateString())->exists();

        return $to > $from && in_array($day->isoWeekday(), $branch['weekdays'], true) && ! $holiday;
    }

    /** @return array{id:int, zone:string, weekdays:?list<int>}|null */
    private function branchFor(\stdClass $application): ?array
    {
        // At Scheduling the candidate's days are counted at the interview branch, the one the slots use (Suresh, m2).
        if ($application->status === self::AWAITING_PICKS) {
            $branch = app(InterviewSlots::class)->branchFor($application);

            return $branch === null ? null : ['id' => $branch['id'], 'zone' => $branch['zone'], 'weekdays' => $branch['weekdays']];
        }
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
