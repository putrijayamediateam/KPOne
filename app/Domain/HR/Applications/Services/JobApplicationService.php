<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-13 staff side (HR-PLAN 15.2), with HR-17a's stages (HR-PLAN 19.3). Director and HR Manager keep the open
 * positions (each with a type), move an application through New, Phone screening (a recorded call), Scheduling,
 * Interview and then Passed or Failed, or Not taken forward or Withdrawn (a closed application stays closed), open a
 * CV (the open is recorded before any byte is sent) and set the retention. Turning an applicant into staff stays with
 * the account administrator through StaffProvisioningService; nothing here creates an account. Every write: a fresh
 * permission check under lock, DB::transaction, row locks, lock_version, an idempotency key and audit with ids and
 * codes only, never a name, phone, email, message or CV.
 */
final class JobApplicationService
{
    /** @var array<string, string> the stages, in the order HR moves through them (HR-17a; HR-PLAN 19.3) */
    public const STATUSES = [
        'new' => 'New',
        'phone_screening' => 'Phone screening',
        'scheduling' => 'Scheduling',
        'interview' => 'Interview',
        'passed' => 'Passed',
        'failed' => 'Failed',
        'not_taken_forward' => 'Not taken forward',
        'auto_rejected' => 'Auto-rejected',
        'withdrawn' => 'Withdrawn',
        // HR-13's steps, kept readable for older applications; nothing new moves to them.
        'shortlisted' => 'Shortlisted',
        'offered' => 'Offered',
    ];

    /**
     * The moves HR makes with "Move to". Phone screening goes on to Scheduling only through a recorded passing
     * screening (screen()); Auto-rejected is set only by the daily check (HR-17b).
     *
     * @var array<string, list<string>>
     */
    public const NEXT = [
        'new' => ['phone_screening', 'not_taken_forward', 'withdrawn'],
        'phone_screening' => ['not_taken_forward', 'withdrawn'],
        'scheduling' => ['interview', 'not_taken_forward', 'withdrawn'],
        'interview' => ['passed', 'failed', 'withdrawn'],
        // Older HR-13 applications finish their way.
        'shortlisted' => ['interview', 'not_taken_forward', 'withdrawn'],
        'offered' => ['withdrawn'],
    ];

    /** The stages an application is still open at; the others are closed and start the retention. */
    public const OPEN = ['new', 'shortlisted', 'phone_screening', 'scheduling', 'interview'];

    /** @var array<string, string> the position types; each has its own questions on the form (HR-PLAN 19.4) */
    public const TYPES = [
        'ca' => 'Clinic Assistant',
        'intern' => 'Intern',
        'locum' => 'Locum',
        'resident_doctor' => 'Resident Doctor',
    ];

    public const CHECKLIST_MAX = 15;

    /** @var array<string, string> why a phone screening was rejected (Daniel, HR-17a) */
    public const REJECT_REASONS = [
        'speaking' => 'How they came across on the phone',
        'availability' => 'Availability does not fit',
        'experience' => 'Experience does not fit',
        'not_reachable' => 'Could not be reached',
        'other' => 'Other',
    ];

    /** @var array<string, string> the stage HR moves to and the fixed message the candidate gets (HR-17b) */
    public const TELLS = [
        'not_taken_forward' => 'not_taken_forward',
        'withdrawn' => 'withdrawn',
        'passed' => 'passed',
        'failed' => 'failed',
    ];

    /** A first or shorter retention waits this long before the purge may use it, so a mistake can be undone. */
    public const GRACE_DAYS = 7;

    public function __construct(private RecordSupport $support, private HiringMessenger $messenger) {}

    /** @param array<string, mixed> $input */
    public function addPosition(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $title = self::title($input['title'] ?? null);
        $type = self::type($input['position_type'] ?? 'ca');

        return DB::transaction(function () use ($actor, $input, $key, $title, $type): string {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $branchId = $this->branchId($actor, $input['branch_id'] ?? null);
            $hash = $this->support->hash([$actor->id, 'job_position_add', $title, $branchId, $type]);
            if (($replay = $this->support->replay($actor, $key, 'job_position_add', $hash)) !== null) {
                return $replay;
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_job_positions')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'title' => $title,
                'position_type' => $type, 'branch_id' => $branchId, 'is_open' => true, 'created_by_user_id' => $actor->id, 'lock_version' => 1,
                'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'job_position_add', $hash, $publicId);
            $this->support->audit->record('hr.applications.position_added', null, ['position_id' => $id], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Renames a position, changes its branch, or opens or closes it. A closed position leaves the public form;
     * its applications stay.
     *
     * @param  array<string, mixed>  $input
     */
    public function updatePosition(User $actor, string $positionId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $title = self::title($input['title'] ?? null);
        $open = filter_var($input['is_open'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($open === null) {
            throw ValidationException::withMessages(['is_open' => 'Choose open or closed.']);
        }
        $type = isset($input['position_type']) ? self::type($input['position_type']) : null;
        DB::transaction(function () use ($actor, $positionId, $input, $key, $title, $open, $type): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $branchId = $this->branchId($actor, $input['branch_id'] ?? null);
            $hash = $this->support->hash([$actor->id, 'job_position_update', $positionId, $title, $branchId, $open, $type]);
            if ($this->support->replay($actor, $key, 'job_position_update', $hash) !== null) {
                return;
            }
            $position = $this->position($actor, $positionId);
            $this->support->assertLock($position, $input);
            $type ??= (string) $position->position_type;
            $changed = array_keys(array_filter(['title' => $position->title !== $title, 'branch' => $position->branch_id !== $branchId,
                'is_open' => (bool) $position->is_open !== $open, 'position_type' => $position->position_type !== $type]));
            if ($changed === []) {
                throw ValidationException::withMessages(['position' => 'Nothing was changed.']);
            }
            DB::table('hr_job_positions')->where('id', $position->id)->update(['title' => $title, 'branch_id' => $branchId, 'is_open' => $open, 'position_type' => $type,
                'lock_version' => (int) $position->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'job_position_update', $hash, $positionId);
            $this->support->audit->record('hr.applications.position_updated', null, ['position_id' => (int) $position->id, 'fields' => $changed],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function move(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $to = is_string($input['to'] ?? null) ? $input['to'] : '';
        DB::transaction(function () use ($actor, $applicationId, $input, $key, $to): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_application_move', $applicationId, $to]);
            if ($this->support->replay($actor, $key, 'job_application_move', $hash) !== null) {
                return;
            }
            $application = $this->application($actor, $applicationId, true);
            $this->support->assertLock($application, $input);
            if ($application->purged_at !== null || ! in_array($to, self::NEXT[$application->status] ?? [], true)) {
                throw ValidationException::withMessages(['to' => $application->closed_at !== null && $application->status !== 'offered'
                    ? 'This application is closed. Reload the page.'
                    : ($application->status === 'phone_screening' && $to === 'scheduling'
                        ? 'Record the phone screening to move on to scheduling.'
                        : 'Choose one of the next steps shown.')]);
            }
            $closed = ! in_array($to, self::OPEN, true);
            if ($to === 'withdrawn') {
                InterviewSlots::releaseOnWithdraw((int) $application->id, $actor->id);
            }
            DB::table('hr_job_applications')->where('id', $application->id)->update(['status' => $to, 'closed_at' => $closed ? now()->utc() : null,
                'stage_entered_at' => now()->utc(), 'lock_version' => (int) $application->lock_version + 1, 'updated_at' => now()->utc()]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'status_changed', 'from_status' => $application->status, 'to_status' => $to, 'actor_user_id' => $actor->id,
                'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'job_application_move', $hash, $applicationId);
            $this->support->audit->record('hr.applications.moved', null, ['application_id' => (int) $application->id, 'from' => $application->status,
                'to' => $to], $actor, null, $actor->organisation_id);
            // HR-17b: the candidate is told of a decision (HR-PLAN 19.3 and 19.8).
            if (isset(self::TELLS[$to])) {
                $this->messenger->record($application, self::TELLS[$to], $to);
            }
        }, 3);
    }

    /**
     * Records the open, then returns the decrypted CV. Nothing is returned if the record cannot be written.
     *
     * @return array{content:string, filename:string}
     */
    public function openCv(User $actor, string $applicationId): array
    {
        $application = DB::transaction(function () use ($actor, $applicationId): \stdClass {
            $actor = $this->support->authority->lock($actor, ApplicationPermissions::MANAGE);
            $application = $this->application($actor, $applicationId, false);
            if ($application->cv_path === null) {
                throw ValidationException::withMessages(['application' => 'This CV was deleted after the retention period.']);
            }
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'cv_opened', 'actor_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->audit->record('hr.applications.cv_opened', null, ['application_id' => (int) $application->id], $actor, null, $actor->organisation_id);

            return $application;
        }, 3);
        $stored = Storage::disk(JobApplicationIntake::DISK)->exists((string) $application->cv_path) ? Storage::disk(JobApplicationIntake::DISK)->get((string) $application->cv_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR application CV could not be decrypted', ['application_id' => $application->id]);
            abort(404);
        }

        return ['content' => $content, 'filename' => 'cv-'.substr((string) $application->public_id, 0, 8).'.pdf'];
    }

    /**
     * A new retention version: days after an application closes before its values and CV are deleted, or none (keep
     * everything). A first or shorter retention takes effect only after the grace days.
     *
     * @param  array<string, mixed>  $input
     */
    public function setRetention(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $raw = $input['retention_days'] ?? null;
        $days = $raw === null || $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($days === false) {
            throw ValidationException::withMessages(['retention_days' => 'Enter 1 to 3,650 days, or leave it empty to keep applications.']);
        }
        DB::transaction(function () use ($actor, $input, $key, $days): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_application_retention', $days]);
            if ($this->support->replay($actor, $key, 'job_application_retention', $hash) !== null) {
                return;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_job_application_settings:'.$actor->organisation_id]);
            $current = DB::table('hr_job_application_settings')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== ($current === null ? 0 : (int) $current->version)) {
                throw ValidationException::withMessages(['retention_days' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            $currentDays = $current?->retention_days === null ? null : (int) $current->retention_days;
            if ($current !== null && $currentDays === $days) {
                throw ValidationException::withMessages(['retention_days' => 'Nothing was changed.']);
            }
            $today = self::today($actor->organisation_id);
            // Longer, or none: at once. First or shorter: after the grace days.
            $waits = $days !== null && ($currentDays === null || $days < $currentDays);
            $version = $current === null ? 1 : (int) $current->version + 1;
            DB::table('hr_job_application_settings')->insert(['organisation_id' => $actor->organisation_id, 'version' => $version, 'retention_days' => $days,
                'effective_from' => $waits ? CarbonImmutable::parse($today)->addDays(self::GRACE_DAYS)->toDateString() : $today,
                'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'job_application_retention', $hash, (string) $version);
            $this->support->audit->record('hr.applications.retention_set', null, ['version' => $version, 'days' => $days], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * HR-17a: the phone-screening call. Records the ticked checklist items, a 1 to 5 rating, an optional note (encrypted,
     * never audited) and pass or reject; pass moves the application to Scheduling, reject to Not taken forward. One
     * screening per application.
     *
     * @param  array<string, mixed>  $input
     */
    public function screen(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $outcome = in_array($input['outcome'] ?? null, ['pass', 'reject'], true) ? (string) $input['outcome'] : null;
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $rawTicks = is_array($input['ticked'] ?? null) ? $input['ticked'] : [];
        $note = is_string($input['note'] ?? null) ? trim($input['note']) : '';
        $errors = [];
        if ($outcome === null) {
            $errors['outcome'] = 'Choose pass or reject.';
        }
        if ($rating === false) {
            $errors['rating'] = 'Give a rating from 1 to 5.';
        }
        $reason = $outcome === 'reject' && is_string($input['reject_reason'] ?? null) && isset(self::REJECT_REASONS[$input['reject_reason']])
            ? (string) $input['reject_reason'] : null;
        if ($outcome === 'reject' && $reason === null) {
            $errors['reject_reason'] = 'Choose why the call is rejected.';
        }
        if (mb_strlen($note) > 500) {
            $errors['note'] = 'Use at most 500 characters.';
        }
        $ticks = [];
        foreach ($rawTicks as $tick) {
            $index = filter_var($tick, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::CHECKLIST_MAX - 1]]);
            if ($index === false) {
                $errors['ticked'] = 'Tick only items from the checklist.';
                break;
            }
            $ticks[] = $index;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $ticks = array_values(array_unique($ticks));
        sort($ticks);
        DB::transaction(function () use ($actor, $applicationId, $input, $key, $outcome, $rating, $ticks, $note, $reason): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_application_screen', $applicationId, $outcome, $rating, $ticks, hash('sha256', $note), $reason]);
            if ($this->support->replay($actor, $key, 'job_application_screen', $hash) !== null) {
                return;
            }
            $application = $this->application($actor, $applicationId, true);
            $this->support->assertLock($application, $input);
            if ($application->status !== 'phone_screening' || $application->purged_at !== null) {
                throw ValidationException::withMessages(['outcome' => 'This application is not at phone screening. Reload the page.']);
            }
            $checklist = DB::table('hr_job_screening_checklists')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            $items = $checklist === null ? [] : (array) json_decode((string) $checklist->items, true);
            if ($ticks !== [] && max($ticks) >= count($items)) {
                throw ValidationException::withMessages(['ticked' => 'The checklist changed. Reload the page.']);
            }
            $to = $outcome === 'pass' ? 'scheduling' : 'not_taken_forward';
            $now = now()->utc();
            DB::table('hr_job_screenings')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'checklist_version' => $checklist === null ? null : (int) $checklist->version, 'ticked' => json_encode($ticks, JSON_THROW_ON_ERROR),
                'rating' => $rating, 'note_encrypted' => $note === '' ? null : Crypt::encryptString($note), 'outcome' => $outcome,
                'reject_reason' => $reason,
                'actor_user_id' => $actor->id, 'created_at' => $now]);
            DB::table('hr_job_applications')->where('id', $application->id)->update(['status' => $to, 'closed_at' => $to === 'scheduling' ? null : $now,
                'stage_entered_at' => $now, 'lock_version' => (int) $application->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert([
                ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'screened', 'from_status' => null,
                    'to_status' => null, 'actor_user_id' => $actor->id, 'created_at' => $now],
                ['organisation_id' => $actor->organisation_id, 'application_id' => $application->id, 'action' => 'status_changed',
                    'from_status' => 'phone_screening', 'to_status' => $to, 'actor_user_id' => $actor->id, 'created_at' => $now],
            ]);
            $this->support->remember($actor, $key, 'job_application_screen', $hash, $applicationId);
            $this->support->audit->record('hr.applications.screened', null, ['application_id' => (int) $application->id, 'outcome' => $outcome,
                'reason' => $reason, 'ticked' => count($ticks)], $actor, null, $actor->organisation_id);
            $this->messenger->record($application, $outcome === 'pass' ? 'screening_passed' : 'not_taken_forward', $to);
        }, 3);
    }

    /**
     * HR-17a: a new version of the phone-screening call checklist (1 to 15 short questions; KPOne ships none).
     *
     * @param  array<string, mixed>  $input
     */
    public function setChecklist(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $items = [];
        foreach (is_array($input['items'] ?? null) ? $input['items'] : [] as $item) {
            $text = is_string($item) ? trim((string) preg_replace('/\s+/u', ' ', $item)) : '';
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) < 3 || mb_strlen($text) > 160) {
                throw ValidationException::withMessages(['items' => 'Each question needs 3 to 160 characters.']);
            }
            $items[] = $text;
        }
        if ($items === [] || count($items) > self::CHECKLIST_MAX) {
            throw ValidationException::withMessages(['items' => 'Enter 1 to 15 questions.']);
        }
        if (count(array_unique(array_map('mb_strtolower', $items))) !== count($items)) {
            throw ValidationException::withMessages(['items' => 'Each question can appear only once.']);
        }
        DB::transaction(function () use ($actor, $input, $key, $items): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_screening_checklist', $items]);
            if ($this->support->replay($actor, $key, 'job_screening_checklist', $hash) !== null) {
                return;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_job_screening_checklists:'.$actor->organisation_id]);
            $current = DB::table('hr_job_screening_checklists')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== ($current === null ? 0 : (int) $current->version)) {
                throw ValidationException::withMessages(['items' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            if ($current !== null && json_decode((string) $current->items, true) === $items) {
                throw ValidationException::withMessages(['items' => 'Nothing was changed.']);
            }
            $version = $current === null ? 1 : (int) $current->version + 1;
            DB::table('hr_job_screening_checklists')->insert(['organisation_id' => $actor->organisation_id, 'version' => $version,
                'items' => json_encode($items, JSON_THROW_ON_ERROR), 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'job_screening_checklist', $hash, (string) $version);
            $this->support->audit->record('hr.applications.checklist_saved', null, ['version' => $version, 'items' => count($items)], $actor, null, $actor->organisation_id);
        }, 3);
    }

    public static function type(mixed $value): string
    {
        if (! is_string($value) || ! isset(self::TYPES[$value])) {
            throw ValidationException::withMessages(['position_type' => 'Choose the kind of position.']);
        }

        return $value;
    }

    /**
     * The retention in force on a date: the newest version already effective, or null (keep everything).
     */
    public static function retentionOn(int $organisationId, string $date): ?int
    {
        $row = DB::table('hr_job_application_settings')->where('organisation_id', $organisationId)->where('effective_from', '<=', $date)
            ->orderByDesc('version')->first(['retention_days']);

        return $row?->retention_days === null ? null : (int) $row->retention_days;
    }

    /**
     * Daily: for applications past the retention in force, deletes the CV and clears every personal value. A closed
     * application counts from when it closed; one left open counts from when it was sent, so an application nobody
     * moved is not kept for ever (Suresh, HR-13 M1). The application row, its step and its events stay.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        $organisations = DB::table('hr_job_application_settings')->distinct()->pluck('organisation_id');
        foreach ($organisations as $organisationId) {
            $organisationId = (int) $organisationId;
            $today = self::today($organisationId);
            $days = self::retentionOn($organisationId, $today);
            if ($days === null) {
                continue;
            }
            $cutoff = CarbonImmutable::parse($today, self::zone($organisationId))->subDays($days)->startOfDay()->utc();
            DB::table('hr_job_applications')->where('organisation_id', $organisationId)->whereNull('purged_at')
                ->whereRaw('coalesce(closed_at, submitted_at) < ?', [$cutoff])->select('id')->orderBy('id')->chunkById(200, function ($chunk) use (&$count, $organisationId, $cutoff): void {
                    foreach ($chunk as $candidate) {
                        $path = DB::transaction(function () use ($candidate, $organisationId, $cutoff): ?string {
                            $row = DB::table('hr_job_applications')->where('id', $candidate->id)->lockForUpdate()->first();
                            if ($row === null || $row->purged_at !== null || CarbonImmutable::parse($row->closed_at ?? $row->submitted_at)->gte($cutoff)) {
                                return null;
                            }
                            DB::table('hr_job_applications')->where('id', $row->id)->update(['name_encrypted' => null, 'phone_encrypted' => null,
                                'email_encrypted' => null, 'message_encrypted' => null, 'details_encrypted' => null, 'cv_path' => null, 'status_token_hash' => null, 'purged_at' => now()->utc(),
                                'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now()->utc()]);
                            DB::table('hr_job_screenings')->where('application_id', $row->id)->whereNotNull('note_encrypted')->update(['note_encrypted' => null]);
                            DB::table('hr_job_application_events')->insert(['organisation_id' => $organisationId, 'application_id' => $row->id,
                                'action' => 'purged', 'created_at' => now()->utc()]);
                            $this->support->audit->record('hr.applications.purged', null, ['application_id' => (int) $row->id], null, null, $organisationId);

                            return (string) $row->cv_path;
                        }, 3);
                        if ($path !== null) {
                            if (! Storage::disk(JobApplicationIntake::DISK)->delete($path)) {
                                Log::warning('HR application CV could not be deleted by the purge', ['application_id' => $candidate->id]);
                            }
                            $count++;
                        }
                    }
                });
        }

        return $count;
    }

    public static function today(int $organisationId): string
    {
        return CarbonImmutable::now(self::zone($organisationId))->toDateString();
    }

    public static function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }

    public static function title(mixed $value): string
    {
        $title = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if (mb_strlen($title) < 3 || mb_strlen($title) > 80) {
            throw ValidationException::withMessages(['title' => 'Enter a position title of 3 to 80 characters.']);
        }

        return $title;
    }

    private function branchId(User $actor, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'any') {
            return null;
        }

        return (int) $this->support->authority->branch($actor, $value)->id;
    }

    private function position(User $actor, string $positionId): \stdClass
    {
        if (! Str::isUuid($positionId)) {
            abort(404);
        }
        $row = DB::table('hr_job_positions')->where('organisation_id', $actor->organisation_id)->where('public_id', $positionId)->lockForUpdate()->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }

    private function application(User $actor, string $applicationId, bool $lock): \stdClass
    {
        if (! Str::isUuid($applicationId)) {
            abort(404);
        }
        $query = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }
}
