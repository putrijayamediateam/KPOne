<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-17c interview slots (HR-PLAN 19.6; owner, 2026-11-10). One-hour slots start at 9, 10, 11 or 12 in the morning or 2,
 * 3 or 4 in the afternoon, branch time, on the interview branch's working days less its public holidays, from the next
 * working day up to 10 working days ahead. A slot already confirmed for anyone in the organisation is not offered ("Whole
 * group"). At Scheduling the candidate picks exactly three; HR confirms one (the application moves to Interview) or
 * asks for new picks; HR can cancel a confirmed interview with a fixed reason, which sends the application back to
 * Scheduling for new picks. The candidate is told each time. PostgreSQL holds one confirmed interview per slot and per
 * application; two people confirming one slot at once get one interview and a plain refusal.
 */
final class InterviewSlots
{
    /** @var list<int> branch-local start hours */
    public const HOURS = [9, 10, 11, 12, 14, 15, 16];

    public const AHEAD_WORKING_DAYS = 10;

    public const PICKS = 3;

    /** @var array<string, string> why HR cancels a confirmed interview */
    public const CANCEL_REASONS = [
        'candidate_asked' => 'The candidate asked to change it',
        'clinic_cannot' => 'The clinic cannot do this time',
        'other' => 'Other',
    ];

    private const HORIZON_DAYS = 60;

    public function __construct(private RecordSupport $support, private HiringMessenger $messenger) {}

    /**
     * The interview branch: the position's branch, else the candidate's preferred branch, else the first active branch.
     *
     * @return array{id:int, name:string, zone:string, weekdays:?list<int>}|null
     */
    public function branchFor(\stdClass $application): ?array
    {
        $branchId = DB::table('hr_job_positions')->where('id', $application->position_id)->value('branch_id');
        if ($branchId === null && is_string($application->details_encrypted ?? null)) {
            try {
                $details = json_decode(Crypt::decryptString($application->details_encrypted), true);
            } catch (DecryptException) {
                $details = null;
            }
            $code = is_array($details) ? ($details['preferred_branch'] ?? null) : null;
            if (is_string($code) && $code !== 'any') {
                $branchId = DB::table('branches')->where('organisation_id', $application->organisation_id)->where('is_active', true)->where('code', $code)->value('id');
            }
        }
        $branchId ??= DB::table('branches')->where('organisation_id', $application->organisation_id)->where('is_active', true)->orderBy('id')->value('id');
        $branch = $branchId === null ? null : DB::table('branches')->where('id', $branchId)->first(['id', 'name', 'timezone']);
        if ($branch === null) {
            return null;
        }
        $json = DB::table('hr_branch_working_days')->where('branch_id', $branch->id)->value('weekdays');
        $days = is_string($json) ? json_decode($json, true) : null;

        return ['id' => (int) $branch->id, 'name' => (string) $branch->name, 'zone' => (string) ($branch->timezone ?: 'UTC'),
            'weekdays' => is_array($days) ? array_values(array_map('intval', $days)) : null];
    }

    /**
     * The slots the candidate may pick now, grouped by branch-local day. Empty when the branch has no working days set.
     *
     * @return list<array{date:string, label:string, slots:list<array{start:string, label:string}>}>
     */
    public function available(\stdClass $application): array
    {
        $branch = $this->branchFor($application);
        if ($branch === null || $branch['weekdays'] === null || $branch['weekdays'] === []) {
            return [];
        }
        $today = CarbonImmutable::now($branch['zone'])->startOfDay();
        $holidays = DB::table('hr_public_holidays')->where('branch_id', $branch['id'])
            ->whereBetween('holiday_date', [$today->toDateString(), $today->addDays(self::HORIZON_DAYS)->toDateString()])
            ->pluck('holiday_date')->map(fn ($d): string => substr((string) $d, 0, 10))->flip()->all();
        $days = [];
        for ($i = 1; $i <= self::HORIZON_DAYS && count($days) < self::AHEAD_WORKING_DAYS; $i++) {
            $day = $today->addDays($i);
            if (in_array($day->isoWeekday(), $branch['weekdays'], true) && ! isset($holidays[$day->toDateString()])) {
                $days[] = $day;
            }
        }
        if ($days === []) {
            return [];
        }
        $taken = DB::table('hr_job_interviews')->where('organisation_id', $application->organisation_id)->where('status', 'confirmed')
            ->whereBetween('starts_at', [$days[0]->utc(), end($days)->endOfDay()->utc()])->pluck('starts_at')
            ->map(fn ($at): string => self::key(CarbonImmutable::parse($at)))->flip()->all();
        $out = [];
        foreach ($days as $day) {
            $slots = [];
            foreach (self::HOURS as $hour) {
                $start = $day->setTime($hour, 0);
                if (! isset($taken[self::key($start)])) {
                    $slots[] = ['start' => self::key($start), 'label' => $start->format('g:i A')];
                }
            }
            if ($slots !== []) {
                $out[] = ['date' => $day->toDateString(), 'label' => $day->format('D j M Y'), 'slots' => $slots];
            }
        }

        return $out;
    }

    /**
     * The candidate picks exactly three slots through their private link, once a round.
     *
     * @param  array<string, mixed>  $input
     */
    public function pick(mixed $token, array $input, CandidateLink $links): void
    {
        $starts = array_values(array_unique(array_filter(is_array($input['starts'] ?? null) ? $input['starts'] : [], 'is_string')));
        if (count($starts) !== self::PICKS) {
            throw ValidationException::withMessages(['starts' => 'Choose exactly three times. / Pilih tepat tiga masa.']);
        }
        DB::transaction(function () use ($token, $starts, $links): void {
            $row = $links->resolve($token, true);
            if ($row === null) {
                throw ValidationException::withMessages(['link' => CandidateLink::INVALID]);
            }
            if ($row->status !== 'scheduling') {
                throw ValidationException::withMessages(['starts' => 'Times can be chosen only while the clinic is arranging your interview. / Masa boleh dipilih hanya semasa klinik mengatur temu duga anda.']);
            }
            if (DB::table('hr_job_interview_picks')->where('application_id', $row->id)->where('round', $row->interview_round)->exists()) {
                throw ValidationException::withMessages(['starts' => 'Your times are already with the clinic. / Masa anda sudah dihantar kepada klinik.']);
            }
            $branch = $this->branchFor($row);
            $offered = collect($this->available($row))->flatMap(fn (array $d): array => array_column($d['slots'], 'start'))->flip()->all();
            foreach ($starts as $start) {
                if (! isset($offered[$start])) {
                    throw ValidationException::withMessages(['starts' => 'One of those times is no longer free. Choose again. / Salah satu masa itu tidak lagi kosong. Pilih semula.']);
                }
            }
            sort($starts);
            $now = now()->utc();
            DB::table('hr_job_interview_picks')->insert(['organisation_id' => $row->organisation_id, 'application_id' => $row->id, 'round' => $row->interview_round,
                'starts' => json_encode($starts, JSON_THROW_ON_ERROR), 'branch_id' => $branch['id'] ?? null, 'created_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $row->organisation_id, 'application_id' => $row->id,
                'action' => 'slots_picked', 'actor_user_id' => null, 'created_at' => $now]);
            $this->support->audit->record('hr.applications.slots_picked', null, ['application_id' => (int) $row->id, 'round' => (int) $row->interview_round],
                null, null, (int) $row->organisation_id);
        }, 3);
    }

    /**
     * HR confirms one of the round's three picks. The application moves to Interview and the candidate is told.
     *
     * @param  array<string, mixed>  $input
     */
    public function confirm(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $start = is_string($input['start'] ?? null) ? $input['start'] : '';
        try {
            DB::transaction(function () use ($actor, $applicationId, $input, $key, $start): void {
                $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
                $hash = $this->support->hash([$actor->id, 'job_interview_confirm', $applicationId, $start]);
                if ($this->support->replay($actor, $key, 'job_interview_confirm', $hash) !== null) {
                    return;
                }
                $application = $this->application($actor, $applicationId);
                $this->support->assertLock($application, $input);
                if ($application->status !== 'scheduling' || $application->purged_at !== null) {
                    throw ValidationException::withMessages(['start' => 'This application is not at Scheduling. Reload the page.']);
                }
                $pick = DB::table('hr_job_interview_picks')->where('application_id', $application->id)->where('round', $application->interview_round)->first();
                $picked = $pick === null ? [] : (array) json_decode((string) $pick->starts, true);
                if ($pick === null || ! in_array($start, $picked, true)) {
                    throw ValidationException::withMessages(['start' => 'Confirm one of the candidate\'s three times.']);
                }
                $at = CarbonImmutable::parse($start)->utc();
                if ($at->lte(now())) {
                    throw ValidationException::withMessages(['start' => 'This time has passed. Ask the candidate for new times.']);
                }
                // A holiday or a change of working days since the candidate picked (Suresh, HR-17c).
                if (! $this->isWorkingDay($application, $at)) {
                    throw ValidationException::withMessages(['start' => 'This day is no longer a working day at the branch. Choose another of their times, or ask for new ones.']);
                }
                // The whole group: one interview per slot. The lock makes a second HR person wait, then see it taken.
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hr_job_interview_slot:{$actor->organisation_id}:{$start}"]);
                if (DB::table('hr_job_interviews')->where('organisation_id', $actor->organisation_id)->where('status', 'confirmed')->where('starts_at', $at)->exists()) {
                    throw ValidationException::withMessages(['start' => 'This time was just confirmed for another candidate. Choose another of their times, or ask for new ones.']);
                }
                $now = now()->utc();
                DB::table('hr_job_interviews')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'pick_id' => $pick->id,
                    'round' => $application->interview_round, 'branch_id' => $pick->branch_id, 'starts_at' => $at, 'status' => 'confirmed',
                    'confirmed_by_user_id' => $actor->id, 'confirmed_at' => $now]);
                DB::table('hr_job_applications')->where('id', $application->id)->update(['status' => 'interview', 'stage_entered_at' => $now,
                    'lock_version' => (int) $application->lock_version + 1, 'updated_at' => $now]);
                DB::table('hr_job_application_events')->insert([
                    ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'interview_confirmed', 'from_status' => null,
                        'to_status' => null, 'actor_user_id' => $actor->id, 'created_at' => $now],
                    ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'status_changed', 'from_status' => 'scheduling',
                        'to_status' => 'interview', 'actor_user_id' => $actor->id, 'created_at' => $now],
                ]);
                $this->support->remember($actor, $key, 'job_interview_confirm', $hash, $applicationId);
                $this->support->audit->record('hr.applications.interview_confirmed', null, ['application_id' => (int) $application->id,
                    'round' => (int) $application->interview_round], $actor, null, $actor->organisation_id);
                $this->messenger->record($application, 'interview_confirmed', 'interview', null, [], (int) $application->interview_round);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['start' => 'This time was just confirmed for another candidate. Choose another of their times, or ask for new ones.']);
        }
    }

    /**
     * HR asks the candidate for three new times (none of the picks suits). A new round starts and the candidate is told.
     *
     * @param  array<string, mixed>  $input
     */
    public function askAgain(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $applicationId, $input, $key): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_interview_ask_again', $applicationId]);
            if ($this->support->replay($actor, $key, 'job_interview_ask_again', $hash) !== null) {
                return;
            }
            $application = $this->application($actor, $applicationId);
            $this->support->assertLock($application, $input);
            if ($application->status !== 'scheduling' || $application->purged_at !== null
                || ! DB::table('hr_job_interview_picks')->where('application_id', $application->id)->where('round', $application->interview_round)->exists()) {
                throw ValidationException::withMessages(['start' => 'There are no times from the candidate to decline. Reload the page.']);
            }
            $round = (int) $application->interview_round;
            $now = now()->utc();
            DB::table('hr_job_applications')->where('id', $application->id)->update(['interview_round' => $round + 1,
                'lock_version' => (int) $application->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'picks_declined', 'actor_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'job_interview_ask_again', $hash, $applicationId);
            $this->support->audit->record('hr.applications.picks_declined', null, ['application_id' => (int) $application->id, 'round' => $round],
                $actor, null, $actor->organisation_id);
            $this->messenger->record($application, 'pick_again', 'scheduling', null, [], $round);
        }, 3);
    }

    /**
     * HR cancels a confirmed interview with a fixed reason. The application goes back to Scheduling for new picks.
     *
     * @param  array<string, mixed>  $input
     */
    public function cancel(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = is_string($input['reason'] ?? null) && isset(self::CANCEL_REASONS[$input['reason']]) ? (string) $input['reason'] : null;
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Choose why the interview is cancelled.']);
        }
        DB::transaction(function () use ($actor, $applicationId, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_interview_cancel', $applicationId, $reason]);
            if ($this->support->replay($actor, $key, 'job_interview_cancel', $hash) !== null) {
                return;
            }
            $application = $this->application($actor, $applicationId);
            $this->support->assertLock($application, $input);
            $interview = DB::table('hr_job_interviews')->where('application_id', $application->id)->where('status', 'confirmed')->lockForUpdate()->first();
            if ($application->status !== 'interview' || $interview === null) {
                throw ValidationException::withMessages(['reason' => 'There is no confirmed interview to cancel. Reload the page.']);
            }
            $round = (int) $application->interview_round;
            $now = now()->utc();
            DB::table('hr_job_interviews')->where('id', $interview->id)->update(['status' => 'cancelled', 'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => $now, 'cancel_reason' => $reason]);
            DB::table('hr_job_applications')->where('id', $application->id)->update(['status' => 'scheduling', 'stage_entered_at' => $now,
                'interview_round' => $round + 1, 'lock_version' => (int) $application->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert([
                ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'interview_cancelled', 'from_status' => null,
                    'to_status' => null, 'actor_user_id' => $actor->id, 'created_at' => $now],
                ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'status_changed', 'from_status' => 'interview',
                    'to_status' => 'scheduling', 'actor_user_id' => $actor->id, 'created_at' => $now],
            ]);
            $this->support->remember($actor, $key, 'job_interview_cancel', $hash, $applicationId);
            $this->support->audit->record('hr.applications.interview_cancelled', null, ['application_id' => (int) $application->id, 'round' => $round,
                'reason' => $reason], $actor, null, $actor->organisation_id);
            $this->messenger->record($application, 'interview_cancelled', 'scheduling', null, [], $round);
        }, 3);
    }

    /**
     * When an application at Interview is withdrawn (by HR or the candidate), its confirmed interview frees its slot.
     * Call inside the transaction that withdraws it, with the application row locked.
     */
    public static function releaseOnWithdraw(int $applicationId, ?int $actorId): void
    {
        DB::table('hr_job_interviews')->where('application_id', $applicationId)->where('status', 'confirmed')->update(['status' => 'cancelled',
            'cancelled_by_user_id' => $actorId, 'cancelled_at' => now()->utc(), 'cancel_reason' => 'withdrawn']);
    }

    /** Whether a slot's day is still a working day, not a public holiday, at the interview branch. */
    private function isWorkingDay(\stdClass $application, CarbonImmutable $at): bool
    {
        $branch = $this->branchFor($application);
        if ($branch === null || $branch['weekdays'] === null) {
            return false;
        }
        $local = $at->setTimezone($branch['zone']);

        return in_array($local->isoWeekday(), $branch['weekdays'], true)
            && ! DB::table('hr_public_holidays')->where('branch_id', $branch['id'])->whereDate('holiday_date', $local->toDateString())->exists();
    }

    /**
     * A slot's start as stored and compared: UTC, ISO 8601 with a Z, whole seconds. The PostgreSQL insert check on
     * hr_job_interviews compares picks in exactly this text form, so never store them any other way.
     */
    public static function key(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function application(User $actor, string $applicationId): \stdClass
    {
        if (! Str::isUuid($applicationId)) {
            abort(404);
        }
        $row = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId)->lockForUpdate()->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }
}
