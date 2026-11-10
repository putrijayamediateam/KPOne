<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-17e talent pool (HR-PLAN 19.8). A candidate who ticked the optional pool box, and whose application closed as
 * Passed or Failed, stays in the pool for the clinic-entered period counted from closing (KPOne ships no number; with
 * none set nobody is kept beyond the usual retention). The candidate can leave the pool on their private page at any
 * time, once and for good. HR can invite a pool candidate to an open position of the same type: that starts a new
 * application at phone screening with the same answers and CV, and the candidate gets a new private link.
 *
 * The person is in the pool only through the application they ticked. An invited application holds no tick of its own,
 * so it never starts a new period, and leaving from its private page leaves the pool of the application it came from
 * (Suresh, HR-17e M1).
 */
final class TalentPool
{
    public const RESULTS = ['passed', 'failed'];

    public function __construct(private RecordSupport $support, private HiringMessenger $messenger) {}

    /** The pool period in force on a date, or null when none is set. */
    public static function daysOn(int $organisationId, string $date): ?int
    {
        $row = DB::table('hr_talent_pool_settings')->where('organisation_id', $organisationId)->where('effective_from', '<=', $date)
            ->orderByDesc('version')->first(['pool_days']);

        return $row?->pool_days === null ? null : (int) $row->pool_days;
    }

    /**
     * A new pool period version, or none. As with retention, a first or shorter period waits the grace days.
     *
     * @param  array<string, mixed>  $input  pool_days, expected_version, idempotency_key
     */
    public function setDays(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $raw = $input['pool_days'] ?? null;
        $days = $raw === null || $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($days === false) {
            throw ValidationException::withMessages(['pool_days' => 'Enter 1 to 3,650 days, or leave it empty to keep nobody in the pool.']);
        }
        DB::transaction(function () use ($actor, $input, $key, $days): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'talent_pool_days', $days]);
            if ($this->support->replay($actor, $key, 'talent_pool_days', $hash) !== null) {
                return;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_talent_pool_settings:'.$actor->organisation_id]);
            $current = DB::table('hr_talent_pool_settings')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== ($current === null ? 0 : (int) $current->version)) {
                throw ValidationException::withMessages(['pool_days' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            $currentDays = $current?->pool_days === null ? null : (int) $current->pool_days;
            if ($current !== null && $currentDays === $days) {
                throw ValidationException::withMessages(['pool_days' => 'Nothing was changed.']);
            }
            $today = JobApplicationService::today($actor->organisation_id);
            $waits = $days !== null && ($currentDays === null || $days < $currentDays);
            $version = $current === null ? 1 : (int) $current->version + 1;
            DB::table('hr_talent_pool_settings')->insert(['organisation_id' => $actor->organisation_id, 'version' => $version, 'pool_days' => $days,
                'effective_from' => $waits ? CarbonImmutable::parse($today)->addDays(JobApplicationService::GRACE_DAYS)->toDateString() : $today,
                'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'talent_pool_days', $hash, (string) $version);
            $this->support->audit->record('hr.applications.pool_days_set', null, ['version' => $version, 'days' => $days], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @return array{version:int, days:int|null, pending:array{days:int|null, from:string}|null} */
    public static function settings(int $organisationId): array
    {
        $today = JobApplicationService::today($organisationId);
        $latest = DB::table('hr_talent_pool_settings')->where('organisation_id', $organisationId)->orderByDesc('version')->first();
        $pending = $latest !== null && $latest->effective_from > $today
            ? ['days' => $latest->pool_days === null ? null : (int) $latest->pool_days, 'from' => CarbonImmutable::parse($latest->effective_from)->format('j M Y')] : null;

        return ['version' => $latest === null ? 0 : (int) $latest->version, 'days' => self::daysOn($organisationId, $today), 'pending' => $pending];
    }

    /**
     * What the candidate's page says about the pool: the tick of the application itself, or, for an invited one, of
     * the application it came from.
     *
     * @return array{consented:bool, left:bool}
     */
    public static function candidateView(\stdClass $application): array
    {
        $root = $application->invited_from_application_id === null ? $application
            : DB::table('hr_job_applications')->where('id', $application->invited_from_application_id)->first(['talent_pool_consent', 'pool_withdrawn_at']);

        return ['consented' => (bool) ($root->talent_pool_consent ?? false), 'left' => ($root->pool_withdrawn_at ?? null) !== null];
    }

    /** Whether an application is in the pool today. */
    public static function inPool(\stdClass $application): bool
    {
        return self::until($application) !== null;
    }

    /** The last local date an application is kept in the pool, or null when it is not in the pool. */
    public static function until(\stdClass $application, ?int $days = null): ?string
    {
        if (! in_array($application->status, self::RESULTS, true) || ! $application->talent_pool_consent || $application->pool_withdrawn_at !== null
            || $application->purged_at !== null || $application->closed_at === null) {
            return null;
        }
        $organisationId = (int) $application->organisation_id;
        $today = JobApplicationService::today($organisationId);
        $days ??= self::daysOn($organisationId, $today);
        if ($days === null) {
            return null;
        }
        $until = CarbonImmutable::parse($application->closed_at)->setTimezone(JobApplicationService::zone($organisationId))->addDays($days)->toDateString();

        return $until >= $today ? $until : null;
    }

    /**
     * The candidate leaves the pool through their private link. Recorded once; it never comes back.
     */
    public function leave(mixed $token, CandidateLink $links): void
    {
        DB::transaction(function () use ($token, $links): void {
            $own = $links->resolve($token, true);
            if ($own === null) {
                throw ValidationException::withMessages(['link' => CandidateLink::INVALID]);
            }
            // From an invited application, the tick lives on the application it came from.
            $application = $own->invited_from_application_id === null ? $own
                : DB::table('hr_job_applications')->where('id', $own->invited_from_application_id)->lockForUpdate()->first();
            if ($application === null || ! $application->talent_pool_consent || $application->pool_withdrawn_at !== null) {
                throw ValidationException::withMessages(['pool' => 'You are not in the talent pool. / Anda tiada dalam kumpulan bakat.']);
            }
            DB::table('hr_job_applications')->where('id', $application->id)->update(['pool_withdrawn_at' => now()->utc(),
                'lock_version' => (int) $application->lock_version + 1, 'updated_at' => now()->utc()]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $application->organisation_id, 'application_id' => $application->id,
                'action' => 'pool_left', 'created_at' => now()->utc()]);
            $this->support->audit->record('hr.applications.pool_left', null, ['application_id' => (int) $application->id], null, null, (int) $application->organisation_id);
        }, 3);
    }

    /**
     * HR invites a pool candidate to an open position of the same type. A new application starts at phone screening
     * with the same answers and a copy of the CV; the candidate gets a new private link, by email when hiring email is
     * on. Returns the new private link so HR can pass it on when email is off; it is never stored.
     *
     * @param  array<string, mixed>  $input  position, expected_lock_version, idempotency_key
     */
    public function invite(User $actor, string $applicationId, array $input): ?string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $positionId = is_string($input['position'] ?? null) && Str::isUuid($input['position']) ? (string) $input['position'] : null;
        if ($positionId === null) {
            throw ValidationException::withMessages(['position' => 'Choose an open position.']);
        }
        if (! Str::isUuid($applicationId)) {
            abort(404);
        }
        $path = null;
        $link = null;
        try {
            DB::transaction(function () use ($actor, $applicationId, $input, $key, $positionId, &$path, &$link): void {
                if ($path !== null) {
                    Storage::disk(JobApplicationIntake::DISK)->delete($path);
                    $path = null;
                }
                $link = null;
                $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
                $hash = $this->support->hash([$actor->id, 'talent_pool_invite', $applicationId, $positionId]);
                if ($this->support->replay($actor, $key, 'talent_pool_invite', $hash) !== null) {
                    return;
                }
                $source = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId)->lockForUpdate()->first();
                abort_if(! $source instanceof \stdClass, 404);
                $this->support->assertLock($source, $input);
                if (! self::inPool($source)) {
                    throw ValidationException::withMessages(['application' => 'This candidate is not in the talent pool.']);
                }
                $position = DB::table('hr_job_positions')->where('organisation_id', $actor->organisation_id)->where('public_id', $positionId)->sharedLock()->first();
                $sourceType = DB::table('hr_job_positions')->where('id', $source->position_id)->value('position_type');
                if ($position === null || ! $position->is_open || $position->position_type !== $sourceType) {
                    throw ValidationException::withMessages(['position' => 'Choose an open position of the same type: the answers on the form differ by type.']);
                }
                if (DB::table('hr_job_applications')->where('invited_from_application_id', $source->id)->where('position_id', $position->id)->exists()) {
                    throw ValidationException::withMessages(['position' => 'This candidate was already invited to this position.']);
                }
                $stored = $source->cv_path === null ? null : Storage::disk(JobApplicationIntake::DISK)->get((string) $source->cv_path);
                if (! is_string($stored)) {
                    throw ValidationException::withMessages(['application' => 'The CV of this application could not be read, so it cannot be copied.']);
                }
                try {
                    Crypt::decryptString($stored);
                } catch (DecryptException) {
                    throw ValidationException::withMessages(['application' => 'The CV of this application could not be read, so it cannot be copied.']);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-applications/{$actor->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(JobApplicationIntake::DISK)->put($path, $stored)) {
                    $path = null;
                    throw ValidationException::withMessages(['application' => 'The CV could not be copied. Try again.']);
                }
                [$token, $tokenHash] = CandidateLink::issue();
                $now = now()->utc();
                $id = DB::table('hr_job_applications')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                    'position_id' => $position->id, 'status' => 'phone_screening', 'name_encrypted' => $source->name_encrypted,
                    'phone_encrypted' => $source->phone_encrypted, 'email_encrypted' => $source->email_encrypted, 'message_encrypted' => $source->message_encrypted,
                    // No tick of its own: the person stays in the pool through the source only (Suresh, HR-17e M1).
                    'details_encrypted' => $source->details_encrypted, 'talent_pool_consent' => false, 'cv_path' => $path, 'cv_bytes' => $source->cv_bytes,
                    'cv_sha256' => $source->cv_sha256, 'privacy_notice_version' => $source->privacy_notice_version, 'form_nonce' => bin2hex(random_bytes(16)),
                    'submitted_at' => $now, 'status_token_hash' => $tokenHash, 'stage_entered_at' => $now, 'invited_from_application_id' => $source->id,
                    'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('hr_job_application_events')->insert([
                    ['organisation_id' => $actor->organisation_id, 'application_id' => $source->id, 'action' => 'invited_to_position', 'actor_user_id' => $actor->id, 'created_at' => $now],
                    ['organisation_id' => $actor->organisation_id, 'application_id' => $id, 'action' => 'invited_from_pool', 'actor_user_id' => $actor->id, 'created_at' => $now],
                ]);
                $new = DB::table('hr_job_applications')->where('id', $id)->first();
                $link = CandidateLink::url($token);
                $this->messenger->record($new, 'pool_invited', 'phone_screening', $link);
                $this->support->remember($actor, $key, 'talent_pool_invite', $hash, $publicId);
                $this->support->audit->record('hr.applications.pool_invited', null, ['application_id' => $id, 'from_application_id' => (int) $source->id,
                    'position_id' => (int) $position->id], $actor, null, $actor->organisation_id);
            }, 3);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk(JobApplicationIntake::DISK)->delete($path);
            }
            throw $e;
        }

        return $link;
    }

    /**
     * The pool as HR sees it: who, from which position and result, until when, and the open positions of their type
     * they were not yet invited to.
     *
     * @return list<array<string, mixed>>
     */
    public static function members(int $organisationId, string $zone): array
    {
        $today = JobApplicationService::today($organisationId);
        $days = self::daysOn($organisationId, $today);
        if ($days === null) {
            return [];
        }
        $since = CarbonImmutable::parse($today, $zone)->subDays($days)->startOfDay()->utc();
        $rows = DB::table('hr_job_applications as a')->join('hr_job_positions as p', 'p.id', '=', 'a.position_id')
            ->where('a.organisation_id', $organisationId)->whereIn('a.status', self::RESULTS)->where('a.talent_pool_consent', true)
            ->whereNull('a.pool_withdrawn_at')->whereNull('a.purged_at')->where('a.closed_at', '>=', $since)
            ->orderByDesc('a.closed_at')->orderByDesc('a.id')->limit(200)
            ->get(['a.*', 'p.title', 'p.position_type']);
        $open = DB::table('hr_job_positions')->where('organisation_id', $organisationId)->where('is_open', true)->orderBy('title')->get(['id', 'public_id', 'title', 'position_type']);
        $invited = DB::table('hr_job_applications as n')->join('hr_job_positions as p', 'p.id', '=', 'n.position_id')
            ->whereIn('n.invited_from_application_id', $rows->pluck('id'))->get(['n.invited_from_application_id', 'n.position_id', 'p.title'])
            ->groupBy('invited_from_application_id');

        return array_values($rows->filter(fn (\stdClass $a): bool => self::until($a, $days) !== null)->map(function (\stdClass $a) use ($open, $invited, $zone, $days): array {
            $done = collect($invited[$a->id] ?? [])->pluck('position_id')->all();
            try {
                $name = Crypt::decryptString((string) $a->name_encrypted);
            } catch (DecryptException) {
                $name = 'Name could not be read';
            }

            return ['id' => (string) $a->public_id, 'name' => $name, 'position' => (string) $a->title, 'type' => (string) $a->position_type,
                'result' => (string) $a->status, 'resultLabel' => JobApplicationService::STATUSES[$a->status] ?? (string) $a->status,
                'closedOn' => CarbonImmutable::parse($a->closed_at)->setTimezone($zone)->format('j M Y'),
                'until' => CarbonImmutable::parse((string) self::until($a, $days))->format('j M Y'), 'lockVersion' => (int) $a->lock_version,
                'invitedTo' => array_values(collect($invited[$a->id] ?? [])->pluck('title')->map(fn ($t): string => (string) $t)->all()),
                'positions' => array_values($open->where('position_type', $a->position_type)->whereNotIn('id', $done)
                    ->map(fn (\stdClass $p): array => ['value' => (string) $p->public_id, 'label' => (string) $p->title])->all())];
        })->all());
    }
}
