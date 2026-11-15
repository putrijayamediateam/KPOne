<?php

namespace App\Domain\HR\Incidents\Services;

use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-10d incident reports (HR-PLAN 20.6). Any human staff member reports what happened (encrypted, at most 2,000
 * characters), when, at which branch and of which kind, with up to 3 photos (decoded and re-encoded, so EXIF is gone).
 * The Director or HR Manager acknowledges it, adds notes (never shown to the reporter) and actions with an owner and a
 * due date, and closes it with an outcome. Nobody handles their own report. Patient-care reports are flagged
 * "Needs clinical review". A report is linked to an HR-10c discipline case only by someone who may handle that case.
 * A staff-conduct report may be sent to the Director only (the HR Manager never lists, reads or handles it).
 * Text and photos are encrypted and never audited.
 */
final class IncidentService
{
    public const CATEGORIES = ['workplace_safety' => 'Workplace safety', 'staff_conduct' => 'Staff conduct', 'equipment_premises' => 'Equipment or premises',
        'near_miss' => 'Near miss', 'patient_care' => 'Patient care'];

    public const STATUS = ['reported' => 'Reported', 'acknowledged' => 'Acknowledged', 'closed' => 'Closed'];

    public const MAX_TEXT = 2000;

    public const MAX_NOTE = 1000;

    public const MAX_PHOTOS = 3;

    /** Photos are smaller than staff documents: 5 MB each, as HR-2b leave evidence. */
    public const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    /** How far back a report may go. */
    public const MAX_DAYS_BACK = 365;

    public function __construct(private RecordSupport $support, private DisciplineService $discipline) {}

    public static function zone(int $organisationId): string
    {
        return DisciplineService::zone($organisationId);
    }

    /**
     * Checks each photo before anything is written: JPG or PNG only, decoded and re-encoded (no EXIF).
     *
     * @return list<array{content:string, kind:string, bytes:int, sha256:string}>
     */
    public static function preparePhotos(mixed $files): array
    {
        $files = is_array($files) ? array_values($files) : ($files === null ? [] : [$files]);
        if (count($files) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'Add at most '.self::MAX_PHOTOS.' photos.']);
        }
        $prepared = [];
        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['photos' => 'Use JPG or PNG photos.']);
            }
            $one = LeaveEvidenceFiles::prepare($file, "photos.{$i}", self::MAX_PHOTO_BYTES);
            if ($one['kind'] === 'pdf') {
                throw ValidationException::withMessages(["photos.{$i}" => 'Use a JPG or PNG photo, not a PDF.']);
            }
            $prepared[] = $one;
        }
        if (count(array_unique(array_column($prepared, 'sha256'))) !== count($prepared)) {
            throw ValidationException::withMessages(['photos' => 'The same photo was chosen twice.']);
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $input  branch_id, occurred_on, occurred_time, category, director_only, description, idempotency_key
     * @param  list<array{content:string, kind:string, bytes:int, sha256:string}>  $photos  from preparePhotos()
     */
    public function report(User $actor, array $input, array $photos = []): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $category = is_string($input['category'] ?? null) && array_key_exists($input['category'], self::CATEGORIES) ? $input['category'] : null;
        if ($category === null) {
            throw ValidationException::withMessages(['category' => 'Choose what kind of incident it was.']);
        }
        $directorOnly = filter_var($input['director_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($directorOnly && $category !== 'staff_conduct') {
            throw ValidationException::withMessages(['director_only' => 'Only a staff-conduct report can go to the Director only.']);
        }
        if ($directorOnly && $actor->hasRole('director') && ! User::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->whereKeyNot($actor->id)->whereHas('roles', fn ($q) => $q->where('name', 'director'))->exists()) {
            // Nobody could ever handle it: a Director never handles their own report.
            throw ValidationException::withMessages(['director_only' => "You are the only Director, so nobody else could handle this. Send it without 'Director only', or raise it outside KPOne."]);
        }
        $branch = is_string($input['branch_id'] ?? null) ? Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->where('code', $input['branch_id'])->first() : null;
        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose the branch where it happened.']);
        }
        $occurred = $this->occurredAt($input, $branch);
        $text = self::text($input['description'] ?? null, 'description', 10, self::MAX_TEXT,
            'Describe what happened in 10 to '.self::MAX_TEXT.' characters.');
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $key, $category, $directorOnly, $branch, $occurred, $text, $photos, &$written): string {
                foreach ($written as $stale) {
                    Storage::disk(DocumentService::DISK)->delete($stale);
                }
                $written = [];
                $actor = $this->support->lock($actor, IncidentPermissions::REPORT);
                $hash = $this->support->hash([$actor->id, 'incident_report', $branch->id, $occurred->toIso8601String(), $category, $directorOnly, hash('sha256', $text),
                    array_column($photos, 'sha256')]);
                if (($replay = $this->support->replay($actor, $key, 'incident_report', $hash)) !== null) {
                    return $replay;
                }
                $now = now()->utc();
                $publicId = (string) Str::uuid();
                $incidentId = DB::table('hr_incidents')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                    'branch_id' => $branch->id, 'reporter_user_id' => $actor->id, 'occurred_at' => $occurred->utc(), 'category' => $category, 'director_only' => $directorOnly,
                    'description_encrypted' => Crypt::encryptString($text), 'status' => 'reported', 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                foreach ($photos as $photo) {
                    $photoId = (string) Str::uuid();
                    $path = "hr-incidents/{$actor->organisation_id}/{$photoId}.bin";
                    if (! Storage::disk(DocumentService::DISK)->put($path, Crypt::encryptString($photo['content']))) {
                        throw ValidationException::withMessages(['photos' => 'A photo could not be saved. Try again.']);
                    }
                    $written[] = $path;
                    DB::table('hr_incident_photos')->insert(['public_id' => $photoId, 'organisation_id' => $actor->organisation_id, 'incident_id' => $incidentId,
                        'kind' => $photo['kind'], 'bytes' => $photo['bytes'], 'sha256' => $photo['sha256'], 'stored_path' => $path, 'created_at' => $now]);
                }
                $this->support->remember($actor, $key, 'incident_report', $hash, $publicId);
                // No subject and no category: the audit log does not say who reported what kind of incident.
                $this->support->audit->record('hr.incidents.reported', null, ['incident_id' => $incidentId, 'photos' => count($photos)], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk(DocumentService::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function acknowledge(User $actor, string $incidentId, array $input): void
    {
        $this->change($actor, $incidentId, $input, 'acknowledge', [], function (\stdClass $incident) use ($actor): array {
            if ($incident->status !== 'reported') {
                throw ValidationException::withMessages(['incident' => 'This report is already acknowledged.']);
            }

            return ['status' => 'acknowledged', 'acknowledged_by_user_id' => $actor->id, 'acknowledged_at' => now()->utc()];
        });
    }

    /** @param array<string, mixed> $input outcome, expected_lock_version, idempotency_key */
    public function close(User $actor, string $incidentId, array $input): void
    {
        $outcome = self::text($input['outcome'] ?? null, 'outcome', 3, self::MAX_NOTE, 'Write the outcome in 3 to '.self::MAX_NOTE.' characters.');
        $this->change($actor, $incidentId, $input, 'close', [hash('sha256', $outcome)], function (\stdClass $incident) use ($actor, $outcome): array {
            if ($incident->status === 'reported') {
                throw ValidationException::withMessages(['incident' => 'Acknowledge the report before closing it.']);
            }
            if ($incident->status === 'closed') {
                throw ValidationException::withMessages(['incident' => 'This report is already closed.']);
            }
            if (DB::table('hr_incident_actions')->where('incident_id', $incident->id)->whereNull('done_at')->exists()) {
                throw ValidationException::withMessages(['incident' => 'Mark every action done before closing the report.']);
            }

            return ['status' => 'closed', 'outcome_encrypted' => Crypt::encryptString($outcome), 'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc()];
        });
    }

    /** @param array<string, mixed> $input note, idempotency_key */
    public function addNote(User $actor, string $incidentId, array $input): void
    {
        $note = self::text($input['note'] ?? null, 'note', 1, self::MAX_NOTE, 'Write the note in at most '.self::MAX_NOTE.' characters.');
        $this->append($actor, $incidentId, $input, 'note', [hash('sha256', $note)], function (\stdClass $incident) use ($actor, $note): void {
            DB::table('hr_incident_notes')->insert(['organisation_id' => $actor->organisation_id, 'incident_id' => $incident->id,
                'body_encrypted' => Crypt::encryptString($note), 'author_user_id' => $actor->id, 'created_at' => now()->utc()]);
        });
    }

    /** @param array<string, mixed> $input description, owner_id (an active account id), due_on, idempotency_key */
    public function addAction(User $actor, string $incidentId, array $input): void
    {
        $text = self::text($input['description'] ?? null, 'action', 3, 300, 'Describe the action in 3 to 300 characters.');
        $ownerId = filter_var($input['owner_id'] ?? null, FILTER_VALIDATE_INT);
        $owner = is_int($ownerId) ? User::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereKey($ownerId)->first() : null;
        if (! $owner instanceof User) {
            throw ValidationException::withMessages(['owner_id' => 'Choose who will do it.']);
        }
        $due = is_string($input['due_on'] ?? null) ? self::date($input['due_on']) : null;
        $today = CarbonImmutable::now(self::zone((int) $actor->organisation_id))->toDateString();
        if ($due === null || $due < $today) {
            throw ValidationException::withMessages(['due_on' => 'Choose a due date, today or later.']);
        }
        $this->append($actor, $incidentId, $input, 'action', [hash('sha256', $text), $owner->id, $due], function (\stdClass $incident) use ($actor, $text, $owner, $due): void {
            DB::table('hr_incident_actions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'incident_id' => $incident->id,
                'description_encrypted' => Crypt::encryptString($text), 'owner_user_id' => $owner->id, 'due_on' => $due, 'created_by_user_id' => $actor->id,
                'created_at' => now()->utc(), 'lock_version' => 1]);
        });
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function markActionDone(User $actor, string $incidentId, string $actionId, array $input): void
    {
        abort_if(! Str::isUuid($actionId), 404);
        $this->append($actor, $incidentId, $input, 'action_done', [$actionId], function (\stdClass $incident) use ($actor, $actionId, $input): void {
            $action = DB::table('hr_incident_actions')->where('incident_id', $incident->id)->where('public_id', $actionId)->lockForUpdate()->first();
            abort_if(! $action instanceof \stdClass, 404);
            $this->support->assertLock($action, $input);
            if ($action->done_at !== null) {
                throw ValidationException::withMessages(['incident' => 'This action is already done.']);
            }
            DB::table('hr_incident_actions')->where('id', $action->id)->update(['done_by_user_id' => $actor->id, 'done_at' => now()->utc(),
                'lock_version' => (int) $action->lock_version + 1]);
        });
    }

    /**
     * Links a staff-conduct report to an open HR-10c case. Only someone who may handle that case.
     *
     * @param  array<string, mixed>  $input  case_id, expected_lock_version, idempotency_key
     */
    public function linkCase(User $actor, string $incidentId, array $input): void
    {
        $caseId = is_string($input['case_id'] ?? null) && Str::isUuid($input['case_id']) ? $input['case_id'] : null;
        if ($caseId === null) {
            throw ValidationException::withMessages(['case_id' => 'Choose the discipline case.']);
        }
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reporter = $this->reporterId($actor, $incidentId);
        DB::transaction(function () use ($actor, $incidentId, $caseId, $input, $key, $reporter): void {
            $actor = $this->support->lock($actor, IncidentPermissions::MANAGE, [$reporter]);
            if (! $actor->can(DisciplinePermissions::MANAGE)) {
                throw new AuthorizationException('Only someone who handles discipline may link a report to a case.');
            }
            $hash = $this->support->hash([$actor->id, 'incident_link', $incidentId, $caseId]);
            if ($this->support->replay($actor, $key, 'incident_link', $hash) !== null) {
                return;
            }
            $incident = $this->managed($actor, $incidentId);
            $this->support->assertLock($incident, $input);
            if ($incident->discipline_case_id !== null) {
                throw ValidationException::withMessages(['case_id' => 'This report is already linked to a case.']);
            }
            if ($incident->category !== 'staff_conduct') {
                throw ValidationException::withMessages(['case_id' => 'Only a staff-conduct report can be linked to a discipline case.']);
            }
            $case = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('public_id', $caseId)->sharedLock()->first();
            $subject = $case instanceof \stdClass && $case->subject_user_id !== null ? User::query()->whereKey($case->subject_user_id)->first() : null;
            if (! $case instanceof \stdClass || $case->status !== 'open' || ($case->subject_user_id !== null && $subject === null)
                || $this->discipline->refusalFor($actor, $subject) !== null) {
                throw ValidationException::withMessages(['case_id' => 'Choose an open discipline case you may handle.']);
            }
            DB::table('hr_incidents')->where('id', $incident->id)->update(['discipline_case_id' => $case->id, 'linked_by_user_id' => $actor->id,
                'linked_at' => now()->utc(), 'lock_version' => (int) $incident->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'incident_link', $hash, $incidentId);
            $this->support->audit->record('hr.incidents.linked', null, ['incident_id' => (int) $incident->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * The report for its reporter or a manager. A manager's open is recorded first; the reporter's own view is not a
     * disclosure. Returns the row and whether the viewer handles it.
     *
     * @return array{0:\stdClass, 1:bool}
     */
    public function view(User $actor, string $incidentId): array
    {
        $reporter = $this->reporterId($actor, $incidentId);
        if ($reporter === $actor->id && $actor->can(IncidentPermissions::REPORT)) {
            $row = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->where('public_id', $incidentId)->first();
            abort_if(! $row instanceof \stdClass, 404);

            return [$row, false];
        }

        return DB::transaction(function () use ($actor, $incidentId, $reporter): array {
            $actor = $this->support->lock($actor, IncidentPermissions::MANAGE, [$reporter]);
            $row = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->where('public_id', $incidentId)->first();
            abort_if(! $row instanceof \stdClass || ! self::mayHandle($actor, $row), 404);
            $this->support->audit->record('hr.incidents.viewed', null, ['incident_id' => (int) $row->id], $actor, null, $actor->organisation_id);

            return [$row, true];
        }, 3);
    }

    /** @return array{content:string, mime:string, filename:string} */
    public function openPhoto(User $actor, string $incidentId, string $photoId): array
    {
        abort_if(! Str::isUuid($photoId), 404);
        [$incident] = $this->view($actor, $incidentId);
        $photo = DB::table('hr_incident_photos')->where('incident_id', $incident->id)->where('public_id', $photoId)->first();
        abort_if(! $photo instanceof \stdClass, 404);
        $stored = Storage::disk(DocumentService::DISK)->exists($photo->stored_path) ? Storage::disk(DocumentService::DISK)->get($photo->stored_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR incident photo could not be decrypted', ['photo_id' => $photo->id]);
            abort(404);
        }

        return ['content' => $content, 'mime' => $photo->kind === 'png' ? 'image/png' : 'image/jpeg',
            'filename' => 'incident-'.substr((string) $photo->public_id, 0, 8).'.'.($photo->kind === 'png' ? 'png' : 'jpg')];
    }

    /** A report sent to the Director only is not there for anyone else: lists, views and handling all leave it out. */
    public static function mayHandle(User $actor, \stdClass $incident): bool
    {
        return ! (bool) $incident->director_only || $actor->hasRole('director');
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

    /**
     * A status change under the incident lock, with lock_version and an idempotency key.
     *
     * @param  array<string, mixed>  $input
     * @param  list<mixed>  $hashParts
     * @param  callable(\stdClass): array<string, mixed>  $columns
     */
    private function change(User $actor, string $incidentId, array $input, string $action, array $hashParts, callable $columns): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reporter = $this->reporterId($actor, $incidentId);
        DB::transaction(function () use ($actor, $incidentId, $input, $key, $action, $hashParts, $columns, $reporter): void {
            $actor = $this->support->lock($actor, IncidentPermissions::MANAGE, [$reporter]);
            $hash = $this->support->hash([$actor->id, 'incident_'.$action, $incidentId, ...$hashParts]);
            if ($this->support->replay($actor, $key, 'incident_'.$action, $hash) !== null) {
                return;
            }
            $incident = $this->managed($actor, $incidentId);
            $this->support->assertLock($incident, $input);
            DB::table('hr_incidents')->where('id', $incident->id)->update([...$columns($incident), 'lock_version' => (int) $incident->lock_version + 1,
                'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'incident_'.$action, $hash, $incidentId);
            $this->support->audit->record('hr.incidents.'.($action === 'acknowledge' ? 'acknowledged' : 'closed'), null, ['incident_id' => (int) $incident->id],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * An addition (note, action, action done) under the incident lock, with an idempotency key.
     *
     * @param  array<string, mixed>  $input
     * @param  list<mixed>  $hashParts
     * @param  callable(\stdClass): void  $write
     */
    private function append(User $actor, string $incidentId, array $input, string $kind, array $hashParts, callable $write): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reporter = $this->reporterId($actor, $incidentId);
        DB::transaction(function () use ($actor, $incidentId, $key, $kind, $hashParts, $write, $reporter): void {
            $actor = $this->support->lock($actor, IncidentPermissions::MANAGE, [$reporter]);
            $hash = $this->support->hash([$actor->id, 'incident_'.$kind, $incidentId, ...$hashParts]);
            if ($this->support->replay($actor, $key, 'incident_'.$kind, $hash) !== null) {
                return;
            }
            $incident = $this->managed($actor, $incidentId);
            if ($incident->status === 'closed') {
                throw ValidationException::withMessages(['incident' => 'This report is closed. Nothing more can be added.']);
            }
            $write($incident);
            $this->support->remember($actor, $key, 'incident_'.$kind, $hash, $incidentId);
            $this->support->audit->record($kind === 'action_done' ? 'hr.incidents.action_done' : 'hr.incidents.'.$kind.'_added', null, ['incident_id' => (int) $incident->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** The report, locked, after the rule that nobody handles their own report. */
    private function managed(User $actor, string $incidentId): \stdClass
    {
        $incident = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->where('public_id', $incidentId)->lockForUpdate()->first();
        abort_if(! $incident instanceof \stdClass || ! self::mayHandle($actor, $incident), 404);
        if ((int) $incident->reporter_user_id === $actor->id) {
            throw new AuthorizationException('You may not handle your own report. Ask someone else.');
        }

        return $incident;
    }

    private function reporterId(User $actor, string $incidentId): int
    {
        abort_if(! Str::isUuid($incidentId), 404);
        $id = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->where('public_id', $incidentId)->value('reporter_user_id');
        abort_if($id === null, 404);

        return (int) $id;
    }

    /** @param array<string, mixed> $input */
    private function occurredAt(array $input, Branch $branch): CarbonImmutable
    {
        $day = is_string($input['occurred_on'] ?? null) ? self::date($input['occurred_on']) : null;
        $time = is_string($input['occurred_time'] ?? null) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $input['occurred_time']) === 1 ? $input['occurred_time'] : null;
        if ($day === null) {
            throw ValidationException::withMessages(['occurred_on' => 'Choose the date it happened.']);
        }
        if ($time === null) {
            throw ValidationException::withMessages(['occurred_time' => 'Choose the time it happened.']);
        }
        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$day} {$time}", $branch->timezone);
        $now = CarbonImmutable::now($branch->timezone);
        if (! $at instanceof CarbonImmutable || $at->gt($now) || $at->lt($now->subDays(self::MAX_DAYS_BACK))) {
            throw ValidationException::withMessages(['occurred_on' => 'Choose a time in the past, at most a year ago.']);
        }

        return $at;
    }

    private static function date(string $value): ?string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function text(mixed $value, string $field, int $min, int $max, string $message): string
    {
        $text = is_string($value) ? str_replace("\r\n", "\n", trim($value)) : '';
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $text;
    }
}
