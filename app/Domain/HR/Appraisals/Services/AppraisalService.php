<?php

namespace App\Domain\HR\Appraisals\Services;

use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Domain\HR\Appraisals\AppraisalPermissions;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\AttendanceTotals;
use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-10b appraisal (HR-PLAN 20.4). HR enters the criteria (versioned, at most 15; KPOne ships none) and opens a cycle
 * with dates and chosen staff. For each person a manager rates every criterion 1 to 5 with a comment and sends it; the
 * attendance, leave and overtime summary of the cycle's dates is frozen into it then. The person reads it and may add
 * their own comment once; a Director signs it off, and nothing changes after that. Nobody appraises themselves; a
 * Director's or an HR Manager's appraisal is done only by a Director. No ranking, no total score, no automatic decision.
 * Ratings and comments are encrypted and never audited.
 */
final class AppraisalService
{
    public const MAX_CRITERIA = 15;

    public const MAX_COMMENT = 1000;

    /** Days the person has to comment before a Director may sign off without a comment (Suresh M2). */
    public const COMMENT_DAYS = 7;

    public function __construct(private RecordSupport $support, private AttendanceTotals $totals, private LeaveCalendar $calendar,
        private AttendanceSettingsService $attendanceSettings) {}

    public static function canSignOffNow(\stdClass $appraisal): bool
    {
        return $appraisal->staff_commented_at !== null
            || ($appraisal->sent_at !== null && CarbonImmutable::parse((string) $appraisal->sent_at, 'UTC')->addDays(self::COMMENT_DAYS)->lessThanOrEqualTo(CarbonImmutable::now('UTC')));
    }

    public static function signOffFrom(\stdClass $appraisal): string
    {
        return CarbonImmutable::parse((string) $appraisal->sent_at, 'UTC')->addDays(self::COMMENT_DAYS)
            ->setTimezone(self::zone((int) $appraisal->organisation_id))->format('j M Y, g:i A');
    }

    /** @param array<string, mixed> $input criteria: list of {title, description}, idempotency_key */
    public function saveCriteria(User $actor, array $input): int
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $criteria = self::validatedCriteria($input['criteria'] ?? null);

        return DB::transaction(function () use ($actor, $key, $criteria): int {
            $actor = $this->support->lock($actor, AppraisalPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'appraisal_criteria', $criteria]);
            if (($replay = $this->support->replay($actor, $key, 'appraisal_criteria', $hash)) !== null) {
                return (int) $replay;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_appraisal_criteria:'.$actor->organisation_id]);
            $current = DB::table('hr_appraisal_criteria_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if ($current !== null && json_decode((string) $current->criteria, true) === $criteria) {
                throw ValidationException::withMessages(['criteria' => 'Nothing was changed.']);
            }
            $version = $current === null ? 1 : (int) $current->version + 1;
            DB::table('hr_appraisal_criteria_versions')->insert(['organisation_id' => $actor->organisation_id, 'version' => $version,
                'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'appraisal_criteria', $hash, (string) $version);
            $this->support->audit->record('hr.appraisals.criteria_saved', null, ['version' => $version, 'count' => count($criteria)], $actor, null, $actor->organisation_id);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input name, period_start, period_end, worker_ids, idempotency_key */
    public function openCycle(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80 || preg_match('/[<>{}\r\n]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Use 3 to 80 characters, without < > { } or line breaks.']);
        }
        $start = self::date($input['period_start'] ?? null);
        $end = self::date($input['period_end'] ?? null);
        if ($start === null) {
            throw ValidationException::withMessages(['period_start' => 'Choose the first day of the period.']);
        }
        if ($end === null || $end->lt($start) || $end->gt($start->addDays(400))) {
            throw ValidationException::withMessages(['period_end' => 'Choose a last day on or after the first, at most about 13 months later.']);
        }
        $ids = is_array($input['worker_ids'] ?? null) ? array_values(array_unique(array_filter($input['worker_ids'], fn ($v): bool => is_string($v) && Str::isUuid($v)))) : [];
        if ($ids === [] || count($ids) > 300) {
            throw ValidationException::withMessages(['worker_ids' => 'Choose 1 to 300 staff members.']);
        }
        sort($ids);

        return DB::transaction(function () use ($actor, $key, $name, $start, $end, $ids): string {
            $actor = $this->support->lock($actor, AppraisalPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'appraisal_cycle', $name, $start->toDateString(), $end->toDateString(), $ids]);
            if (($replay = $this->support->replay($actor, $key, 'appraisal_cycle', $hash)) !== null) {
                return $replay;
            }
            $criteria = DB::table('hr_appraisal_criteria_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if ($criteria === null) {
                throw ValidationException::withMessages(['criteria' => 'Enter the appraisal criteria first.']);
            }
            $workers = Worker::query()->with('user')->where('organisation_id', $actor->organisation_id)->whereIn('public_id', $ids)->get();
            if ($workers->count() !== count($ids) || $workers->contains(fn (Worker $w): bool => $w->user === null || ! $w->user->is_active)) {
                throw ValidationException::withMessages(['worker_ids' => 'Choose staff with an active account. Locums are not appraised in KPOne.']);
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $cycleId = DB::table('hr_appraisal_cycles')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'name' => $name,
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'criteria_version_id' => $criteria->id, 'status' => 'open',
                'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('hr_appraisals')->insert($workers->sortBy('id')->map(fn (Worker $w): array => ['public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id, 'cycle_id' => $cycleId, 'worker_id' => $w->id, 'user_id' => $w->user_id, 'status' => 'draft',
                'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now])->values()->all());
            $this->support->remember($actor, $key, 'appraisal_cycle', $hash, $publicId);
            $this->support->audit->record('hr.appraisals.cycle_opened', null, ['cycle_id' => $cycleId, 'people' => $workers->count(), 'criteria_version' => (int) $criteria->version],
                $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function closeCycle(User $actor, string $cycleId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $cycleId, $input, $key): void {
            $actor = $this->support->lock($actor, AppraisalPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'appraisal_cycle_close', $cycleId]);
            if ($this->support->replay($actor, $key, 'appraisal_cycle_close', $hash) !== null) {
                return;
            }
            abort_if(! Str::isUuid($cycleId), 404);
            $cycle = DB::table('hr_appraisal_cycles')->where('organisation_id', $actor->organisation_id)->where('public_id', $cycleId)->lockForUpdate()->first();
            abort_if(! $cycle instanceof \stdClass, 404);
            $this->support->assertLock($cycle, $input);
            if ($cycle->status !== 'open') {
                throw ValidationException::withMessages(['cycle' => 'This cycle is already closed.']);
            }
            DB::table('hr_appraisal_cycles')->where('id', $cycle->id)->update(['status' => 'closed', 'closed_at' => now()->utc(), 'closed_by_user_id' => $actor->id,
                'lock_version' => (int) $cycle->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'appraisal_cycle_close', $hash, $cycleId);
            $this->support->audit->record('hr.appraisals.cycle_closed', null, ['cycle_id' => (int) $cycle->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Saves the manager's ratings (a draft may be incomplete) and, with send, sends it: then every criterion needs a
     * rating, and the summary is frozen.
     *
     * @param  array<string, mixed>  $input  ratings: list of {rating, comment}, expected_lock_version, idempotency_key
     */
    public function saveRatings(User $actor, string $appraisalId, array $input, bool $send): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $subject = $this->subject($actor, $appraisalId);
        DB::transaction(function () use ($actor, $appraisalId, $input, $key, $send, $subject): void {
            $actor = $this->support->lock($actor, AppraisalPermissions::MANAGE, [$subject]);
            $action = $send ? 'appraisal_send' : 'appraisal_save';
            $hash = $this->support->hash([$actor->id, $action, $appraisalId, $input['ratings'] ?? null]);
            if ($this->support->replay($actor, $key, $action, $hash) !== null) {
                return;
            }
            [$appraisal, $cycle] = $this->managed($actor, $appraisalId);
            $this->support->assertLock($appraisal, $input);
            if ($appraisal->status !== 'draft') {
                throw ValidationException::withMessages(['appraisal' => 'This appraisal was already sent. It cannot be changed.']);
            }
            if ($cycle->status !== 'open') {
                throw ValidationException::withMessages(['appraisal' => 'This cycle is closed.']);
            }
            $criteria = self::criteriaOf((int) $cycle->criteria_version_id);
            $ratings = self::validatedRatings($input['ratings'] ?? null, $criteria, $send);
            $now = now()->utc();
            $values = ['ratings_encrypted' => Crypt::encryptString(json_encode($ratings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'lock_version' => (int) $appraisal->lock_version + 1, 'updated_at' => $now];
            if ($send) {
                $summary = $this->summary((int) $actor->organisation_id, (int) $appraisal->user_id, $cycle);
                $values += ['status' => 'sent', 'appraiser_user_id' => $actor->id, 'sent_at' => $now,
                    'summary_encrypted' => Crypt::encryptString(json_encode($summary, JSON_THROW_ON_ERROR))];
            }
            DB::table('hr_appraisals')->where('id', $appraisal->id)->update($values);
            $this->support->remember($actor, $key, $action, $hash, $appraisalId);
            $this->support->audit->record($send ? 'hr.appraisals.sent' : 'hr.appraisals.saved', null, ['appraisal_id' => (int) $appraisal->id,
                'cycle_id' => (int) $cycle->id, 'rated' => count(array_filter($ratings, fn (array $r): bool => $r['rating'] !== null))], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input comment, expected_lock_version, idempotency_key */
    public function comment(User $actor, string $appraisalId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $comment = is_string($input['comment'] ?? null) ? str_replace("\r\n", "\n", trim($input['comment'])) : '';
        if (mb_strlen($comment) < 1 || mb_strlen($comment) > self::MAX_COMMENT) {
            throw ValidationException::withMessages(['comment' => 'Write your comment: up to '.number_format(self::MAX_COMMENT).' characters.']);
        }
        DB::transaction(function () use ($actor, $appraisalId, $input, $key, $comment): void {
            $actor = $this->support->lock($actor, AppraisalPermissions::VIEW_OWN);
            $hash = $this->support->hash([$actor->id, 'appraisal_comment', $appraisalId, $comment]);
            if ($this->support->replay($actor, $key, 'appraisal_comment', $hash) !== null) {
                return;
            }
            abort_if(! Str::isUuid($appraisalId), 404);
            $appraisal = DB::table('hr_appraisals')->where('organisation_id', $actor->organisation_id)->where('public_id', $appraisalId)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_if(! $appraisal instanceof \stdClass || $appraisal->status === 'draft', 404);
            $this->support->assertLock($appraisal, $input);
            if ($appraisal->status !== 'sent' || $appraisal->staff_commented_at !== null) {
                throw ValidationException::withMessages(['comment' => $appraisal->status === 'signed_off'
                    ? 'This appraisal was signed off. It takes no more comments.' : 'Your comment is already recorded.']);
            }
            DB::table('hr_appraisals')->where('id', $appraisal->id)->update(['staff_comment_encrypted' => Crypt::encryptString($comment), 'staff_commented_at' => now()->utc(),
                'lock_version' => (int) $appraisal->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'appraisal_comment', $hash, $appraisalId);
            $this->support->audit->record('hr.appraisals.commented', null, ['appraisal_id' => (int) $appraisal->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function signOff(User $actor, string $appraisalId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $subject = $this->subject($actor, $appraisalId);
        DB::transaction(function () use ($actor, $appraisalId, $input, $key, $subject): void {
            $actor = $this->support->lock($actor, AppraisalPermissions::MANAGE, [$subject]);
            if (! $actor->hasRole('director')) {
                throw new AuthorizationException('Only a Director signs an appraisal off.');
            }
            $hash = $this->support->hash([$actor->id, 'appraisal_sign_off', $appraisalId]);
            if ($this->support->replay($actor, $key, 'appraisal_sign_off', $hash) !== null) {
                return;
            }
            [$appraisal] = $this->managed($actor, $appraisalId);
            $this->support->assertLock($appraisal, $input);
            if ($appraisal->status !== 'sent') {
                throw ValidationException::withMessages(['appraisal' => $appraisal->status === 'draft' ? 'Send the appraisal before it is signed off.' : 'This appraisal is already signed off.']);
            }
            if (! self::canSignOffNow($appraisal)) {
                throw ValidationException::withMessages(['appraisal' => 'The person has '.self::COMMENT_DAYS.' days to comment. Sign off once they comment or after '.self::signOffFrom($appraisal).'.']);
            }
            DB::table('hr_appraisals')->where('id', $appraisal->id)->update(['status' => 'signed_off', 'signed_off_by_user_id' => $actor->id, 'signed_off_at' => now()->utc(),
                'lock_version' => (int) $appraisal->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'appraisal_sign_off', $hash, $appraisalId);
            $this->support->audit->record('hr.appraisals.signed_off', null, ['appraisal_id' => (int) $appraisal->id, 'with_comment' => $appraisal->staff_commented_at !== null],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Nobody appraises themselves; a Director's and an HR Manager's appraisal is done only by a Director. Null when the
     * actor may.
     */
    public function refusalFor(User $actor, User $subject): ?string
    {
        if ($subject->id === $actor->id) {
            return 'You may not appraise yourself. Ask someone else.';
        }
        if (($subject->hasRole('director') || $subject->hasRole('hr_manager')) && ! $actor->hasRole('director')) {
            return $subject->hasRole('director') ? 'Only a Director may appraise a Director.' : 'Only a Director may appraise an HR Manager.';
        }

        return null;
    }

    /**
     * The cycle's attendance, leave and overtime for one person, read only. Worked out when asked; frozen at send.
     *
     * @return array{late:int, lateMinutes:int, noClockIn:int, noClockOut:int, leaveDays:float|null, overtimeMinutes:int}
     */
    public function summary(int $organisationId, int $userId, \stdClass $cycle): array
    {
        $from = CarbonImmutable::parse((string) $cycle->period_start);
        $to = CarbonImmutable::parse((string) $cycle->period_end);
        $row = $this->totals->summary($organisationId, [$userId], $from, $to)[$userId] ?? [];
        $overtime = array_sum(array_map('intval', (array) ($row['overtime'] ?? [])));

        $today = CarbonImmutable::now(self::zone($organisationId))->toDateString();

        return ['asOf' => min($today, $to->toDateString()), 'partial' => $today < $to->toDateString(),
            'rulesMissing' => $this->attendanceSettings->forDate($organisationId, $from->toDateString()) === null,
            'late' => (int) ($row['late'] ?? 0), 'lateMinutes' => (int) ($row['lateMinutes'] ?? 0), 'noClockIn' => (int) ($row['noClockIn'] ?? 0),
            'noClockOut' => (int) ($row['noClockOut'] ?? 0), 'leaveDays' => $this->leaveDays($organisationId, $userId, $from, $to), 'overtimeMinutes' => $overtime];
    }

    /** @return list<array{title:string, description:string}> */
    public static function criteriaOf(int $versionId): array
    {
        $json = DB::table('hr_appraisal_criteria_versions')->where('id', $versionId)->value('criteria');
        $criteria = json_decode((string) $json, true);

        return is_array($criteria) ? array_values($criteria) : [];
    }

    /** @return list<array{rating:int|null, comment:string}> */
    public static function decryptRatings(?string $encrypted, int $count): array
    {
        $ratings = [];
        if (is_string($encrypted)) {
            try {
                $ratings = json_decode(Crypt::decryptString($encrypted), true) ?: [];
            } catch (DecryptException) {
                $ratings = [];
            }
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $r = is_array($ratings[$i] ?? null) ? $ratings[$i] : [];
            $out[] = ['rating' => isset($r['rating']) && is_int($r['rating']) ? $r['rating'] : null, 'comment' => is_string($r['comment'] ?? null) ? $r['comment'] : ''];
        }

        return $out;
    }

    public static function decrypt(?string $encrypted): ?string
    {
        if (! is_string($encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return list<array{title:string, description:string}> */
    public static function validatedCriteria(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > self::MAX_CRITERIA) {
            throw ValidationException::withMessages(['criteria' => 'Enter 1 to '.self::MAX_CRITERIA.' criteria.']);
        }
        $out = [];
        $seen = [];
        foreach (array_values($value) as $i => $c) {
            $title = is_array($c) && is_string($c['title'] ?? null) ? trim($c['title']) : '';
            $description = is_array($c) && is_string($c['description'] ?? null) ? trim($c['description']) : '';
            if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || preg_match('/[<>{}\r\n]/', $title) === 1) {
                throw ValidationException::withMessages(["criteria.{$i}.title" => 'Use 3 to 80 characters, without < > { } or line breaks.']);
            }
            if (mb_strlen($description) > 300 || preg_match('/[<>]/', $description) === 1) {
                throw ValidationException::withMessages(["criteria.{$i}.description" => 'Use at most 300 characters, without < or >.']);
            }
            if (isset($seen[mb_strtolower($title)])) {
                throw ValidationException::withMessages(["criteria.{$i}.title" => 'Each criterion needs its own title.']);
            }
            $seen[mb_strtolower($title)] = true;
            $out[] = ['title' => $title, 'description' => $description];
        }

        return $out;
    }

    /**
     * @param  list<array{title:string, description:string}>  $criteria
     * @return list<array{rating:int|null, comment:string}>
     */
    private static function validatedRatings(mixed $value, array $criteria, bool $complete): array
    {
        $value = is_array($value) ? array_values($value) : [];
        if (count($value) !== count($criteria)) {
            throw ValidationException::withMessages(['ratings' => 'Reload the page: the criteria do not match.']);
        }
        $out = [];
        foreach ($value as $i => $r) {
            $rating = is_array($r) ? filter_var($r['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : false;
            $comment = is_array($r) && is_string($r['comment'] ?? null) ? str_replace("\r\n", "\n", trim($r['comment'])) : '';
            $given = is_array($r) && array_key_exists('rating', $r) && $r['rating'] !== null && $r['rating'] !== '';
            if ($given && $rating === false) {
                throw ValidationException::withMessages(["ratings.{$i}.rating" => 'Choose 1 to 5.']);
            }
            if ($complete && $rating === false) {
                throw ValidationException::withMessages(["ratings.{$i}.rating" => 'Rate "'.$criteria[$i]['title'].'" before sending.']);
            }
            if (mb_strlen($comment) > 500 || preg_match('/[<>]/', $comment) === 1) {
                throw ValidationException::withMessages(["ratings.{$i}.comment" => 'Use at most 500 characters, without < or >.']);
            }
            $out[] = ['rating' => $rating === false ? null : $rating, 'comment' => $comment];
        }

        return $out;
    }

    /** Approved leave days inside the period, counted on the branch's working days; null when they cannot be counted. */
    private function leaveDays(int $organisationId, int $userId, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $requests = DB::table('hr_leave_requests')->where('organisation_id', $organisationId)->where('user_id', $userId)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->get();
        $halves = 0;
        foreach ($requests as $r) {
            $start = CarbonImmutable::parse((string) $r->start_date);
            $end = CarbonImmutable::parse((string) $r->end_date);
            if ($start->gte($from) && $end->lte($to)) {
                $halves += (int) $r->halves;

                continue;
            }
            $branch = Branch::query()->find($r->branch_id);
            if (! $branch instanceof Branch) {
                return null;
            }
            try {
                $clipStart = $start->lt($from) ? $from : $start;
                $clipEnd = $end->gt($to) ? $to : $end;
                $halves += $this->calendar->measure($branch, $clipStart, $clipEnd, $clipStart->equalTo($start) ? (string) $r->start_part : 'full',
                    $clipEnd->equalTo($end) ? (string) $r->end_part : 'full')['halves'];
            } catch (ValidationException) {
                return null;
            }
        }

        return $halves / 2;
    }

    /** The appraised person's account, read before any lock so it can join the id-ordered account lock. */
    private function subject(User $actor, string $appraisalId): int
    {
        abort_if(! Str::isUuid($appraisalId), 404);
        $userId = DB::table('hr_appraisals')->where('organisation_id', $actor->organisation_id)->where('public_id', $appraisalId)->value('user_id');
        abort_if($userId === null, 404);

        return (int) $userId;
    }

    /** @return array{0:\stdClass, 1:\stdClass} the locked appraisal and its cycle, after the who-may rule */
    private function managed(User $actor, string $appraisalId): array
    {
        $appraisal = DB::table('hr_appraisals')->where('organisation_id', $actor->organisation_id)->where('public_id', $appraisalId)->lockForUpdate()->first();
        abort_if(! $appraisal instanceof \stdClass, 404);
        $subject = User::query()->whereKey($appraisal->user_id)->first();
        $refusal = $subject === null ? 'This appraisal cannot be changed.' : $this->refusalFor($actor, $subject);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
        $cycle = DB::table('hr_appraisal_cycles')->where('id', $appraisal->cycle_id)->sharedLock()->first();
        abort_if(! $cycle instanceof \stdClass, 404);

        return [$appraisal, $cycle];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function zone(int $organisationId): string
    {
        return JobApplicationService::zone($organisationId);
    }
}
