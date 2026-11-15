<?php

namespace App\Domain\HR\Discipline\Services;

use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-10c discipline (HR-PLAN 20.5). A restricted case per staff file: the allegation (encrypted, never changed), then a
 * timeline of notes, linked show-cause letters (HR-10a), the staff member's reply as HR recorded it and files, and one
 * outcome that closes the case. KPOne never ends employment or changes an account: "refer to the Director for
 * termination" is only a recorded outcome. Nobody handles their own case; a Director's or an HR Manager's case only a
 * Director. Every view and file open is audited before anything is shown. Text and files are encrypted and never audited.
 */
final class DisciplineService
{
    public const OUTCOMES = ['no_action' => 'No action', 'verbal_warning' => 'Verbal warning', 'written_warning' => 'Written warning',
        'final_warning' => 'Final warning', 'refer_termination' => 'Refer to the Director for termination'];

    /** Outcomes that need a current HR-10a warning letter for the same person. */
    public const LETTER_OUTCOMES = ['written_warning', 'final_warning'];

    public const MAX_ALLEGATION = 2000;

    public const MAX_NOTE = 2000;

    public const MAX_REPLY = 4000;

    public const MAX_OUTCOME_NOTE = 1000;

    public const MAX_FILES = 20;

    public function __construct(private RecordSupport $support) {}

    public static function zone(int $organisationId): string
    {
        return JobApplicationService::zone($organisationId);
    }

    /** Nobody handles their own case; a Director's or an HR Manager's case only a Director. Null when the actor may. */
    public function refusalFor(User $actor, ?User $subject): ?string
    {
        if ($subject === null) {
            return null; // a locum file with no account
        }
        if ($subject->id === $actor->id) {
            return 'You may not handle a discipline case about yourself.';
        }
        if (($subject->hasRole('director') || $subject->hasRole('hr_manager')) && ! $actor->hasRole('director')) {
            return $subject->hasRole('director') ? "Only a Director may handle a Director's discipline case." : "Only a Director may handle an HR Manager's discipline case.";
        }

        return null;
    }

    /** @param array<string, mixed> $input worker_id, incident_on, allegation, idempotency_key */
    public function open(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $workerId = is_string($input['worker_id'] ?? null) ? $input['worker_id'] : '';
        $allegation = self::text($input['allegation'] ?? null, 'allegation', 10, self::MAX_ALLEGATION, 'Describe the allegation in 10 to '.self::MAX_ALLEGATION.' characters.');
        $incident = $this->pastDate($actor, $input['incident_on'] ?? null, 'incident_on', 'Choose the date it happened, today or earlier.');
        $subjectId = $this->support->workerUserId($actor, $workerId);

        return DB::transaction(function () use ($actor, $key, $workerId, $allegation, $incident, $subjectId): string {
            $actor = $this->support->lock($actor, DisciplinePermissions::MANAGE, [$subjectId]);
            $hash = $this->support->hash([$actor->id, 'discipline_open', $workerId, $incident, hash('sha256', $allegation)]);
            if (($replay = $this->support->replay($actor, $key, 'discipline_open', $hash)) !== null) {
                return $replay;
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->assertMay($actor, $worker->user_id);
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $caseId = DB::table('hr_discipline_cases')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                'worker_id' => $worker->id, 'subject_user_id' => $worker->user_id, 'allegation_encrypted' => Crypt::encryptString($allegation),
                'incident_on' => $incident, 'opened_by_user_id' => $actor->id, 'opened_at' => $now, 'status' => 'open', 'lock_version' => 1,
                'created_at' => $now, 'updated_at' => $now]);
            $this->event($actor, $caseId, 'opened');
            $this->support->remember($actor, $key, 'discipline_open', $hash, $publicId);
            // No audit subject: the audit log must not show whom a case is about (Suresh, M2).
            $this->support->audit->record('hr.discipline.case_opened', null, ['case_id' => $caseId], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input note, idempotency_key */
    public function addNote(User $actor, string $caseId, array $input): void
    {
        $note = self::text($input['note'] ?? null, 'note', 1, self::MAX_NOTE, 'Write the note in at most '.self::MAX_NOTE.' characters.');
        $this->append($actor, $caseId, $input, 'note', [hash('sha256', $note)], fn (\stdClass $case): array => ['body_encrypted' => Crypt::encryptString($note)]);
    }

    /** @param array<string, mixed> $input reply, received_on, idempotency_key */
    public function recordReply(User $actor, string $caseId, array $input): void
    {
        $reply = self::text($input['reply'] ?? null, 'reply', 1, self::MAX_REPLY, 'Type the reply in at most '.self::MAX_REPLY.' characters.');
        $received = $this->pastDate($actor, $input['received_on'] ?? null, 'received_on', 'Choose the date the reply was received, today or earlier.');
        $this->append($actor, $caseId, $input, 'reply', [$received, hash('sha256', $reply)],
            fn (\stdClass $case): array => ['body_encrypted' => Crypt::encryptString($reply), 'occurred_on' => $received]);
    }

    /** @param array<string, mixed> $input letter_id, idempotency_key */
    public function linkShowCause(User $actor, string $caseId, array $input): void
    {
        $letterId = is_string($input['letter_id'] ?? null) && Str::isUuid($input['letter_id']) ? $input['letter_id'] : null;
        if ($letterId === null) {
            throw ValidationException::withMessages(['letter_id' => 'Choose the show-cause letter.']);
        }
        $this->append($actor, $caseId, $input, 'show_cause', [$letterId], function (\stdClass $case) use ($letterId): array {
            $letter = $this->letter($case, $letterId, 'show_cause');
            if (DB::table('hr_discipline_events')->where('case_id', $case->id)->where('kind', 'show_cause')->where('letter_id', $letter->id)->exists()) {
                throw ValidationException::withMessages(['letter_id' => 'This letter is already on the case.']);
            }

            return ['letter_id' => $letter->id];
        });
    }

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared  from DocumentService::prepare()
     * @param  array<string, mixed>  $input  idempotency_key
     */
    public function addFile(User $actor, string $caseId, array $prepared, array $input): void
    {
        $written = [];
        try {
            $this->append($actor, $caseId, $input, 'file', [$prepared['sha256']], function (\stdClass $case) use ($actor, $prepared, &$written): array {
                // A deadlock retry runs this again: remove what an earlier attempt wrote (Suresh, m2).
                foreach ($written as $stale) {
                    Storage::disk(DocumentService::DISK)->delete($stale);
                }
                $written = [];
                if (DB::table('hr_discipline_files')->where('case_id', $case->id)->where('sha256', $prepared['sha256'])->exists()) {
                    throw ValidationException::withMessages(['file' => 'This file is already on the case.']);
                }
                if (DB::table('hr_discipline_files')->where('case_id', $case->id)->count() >= self::MAX_FILES) {
                    throw ValidationException::withMessages(['file' => 'A case holds at most '.self::MAX_FILES.' files.']);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-discipline/{$actor->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(DocumentService::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                    throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
                }
                $written[] = $path;
                $fileId = DB::table('hr_discipline_files')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                    'case_id' => $case->id, 'kind' => $prepared['kind'], 'bytes' => $prepared['bytes'], 'sha256' => $prepared['sha256'],
                    'stored_path' => $path, 'uploaded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);

                return ['file_id' => $fileId];
            }, fn (): array => ['kind' => $prepared['kind'], 'bytes' => $prepared['bytes']]);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk(DocumentService::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $input outcome, letter_id (warnings), note, expected_lock_version, idempotency_key */
    public function close(User $actor, string $caseId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $outcome = is_string($input['outcome'] ?? null) && array_key_exists($input['outcome'], self::OUTCOMES) ? $input['outcome'] : null;
        if ($outcome === null) {
            throw ValidationException::withMessages(['outcome' => 'Choose the outcome.']);
        }
        $letterId = in_array($outcome, self::LETTER_OUTCOMES, true) ? ($input['letter_id'] ?? null) : null;
        if (in_array($outcome, self::LETTER_OUTCOMES, true) && (! is_string($letterId) || ! Str::isUuid($letterId))) {
            throw ValidationException::withMessages(['letter_id' => 'Choose the warning letter you issued in HR letters.']);
        }
        $note = is_string($input['note'] ?? null) && trim($input['note']) !== ''
            ? self::text($input['note'], 'note', 1, self::MAX_OUTCOME_NOTE, 'Keep the note to '.self::MAX_OUTCOME_NOTE.' characters.') : null;
        $subjectId = $this->subjectId($actor, $caseId);
        DB::transaction(function () use ($actor, $caseId, $input, $key, $outcome, $letterId, $note, $subjectId): void {
            $actor = $this->support->lock($actor, DisciplinePermissions::MANAGE, [$subjectId]);
            $hash = $this->support->hash([$actor->id, 'discipline_close', $caseId, $outcome, $letterId, $note === null ? null : hash('sha256', $note)]);
            if ($this->support->replay($actor, $key, 'discipline_close', $hash) !== null) {
                return;
            }
            $case = $this->managed($actor, $caseId);
            $this->support->assertLock($case, $input);
            $letter = is_string($letterId) ? $this->letter($case, $letterId, 'warning') : null;
            $this->event($actor, (int) $case->id, 'outcome', ['letter_id' => $letter?->id, 'body_encrypted' => $note === null ? null : Crypt::encryptString($note)]);
            $now = now()->utc();
            DB::table('hr_discipline_cases')->where('id', $case->id)->update(['status' => 'closed', 'outcome' => $outcome, 'outcome_letter_id' => $letter?->id,
                'outcome_note_encrypted' => $note === null ? null : Crypt::encryptString($note), 'closed_by_user_id' => $actor->id, 'closed_at' => $now,
                'lock_version' => (int) $case->lock_version + 1, 'updated_at' => $now]);
            $this->support->remember($actor, $key, 'discipline_close', $hash, $caseId);
            $this->support->audit->record('hr.discipline.case_closed', null, ['case_id' => (int) $case->id, 'outcome' => $outcome], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Records that the actor is opening the case, then returns the case row. If the audit row cannot be written,
     * nothing is returned (Suresh, HR-4a pattern: record the view first).
     */
    public function view(User $actor, string $caseId): \stdClass
    {
        $subjectId = $this->subjectId($actor, $caseId);

        return DB::transaction(function () use ($actor, $caseId, $subjectId): \stdClass {
            $actor = $this->support->lock($actor, DisciplinePermissions::MANAGE, [$subjectId]);
            $case = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('public_id', $caseId)->first();
            abort_if(! $case instanceof \stdClass, 404);
            $this->assertMay($actor, $case->subject_user_id === null ? null : (int) $case->subject_user_id);
            $this->support->audit->record('hr.discipline.case_viewed', null, ['case_id' => (int) $case->id], $actor, null, $actor->organisation_id);

            return $case;
        }, 3);
    }

    /** @return array{content:string, mime:string, inline:bool, filename:string} */
    public function openFile(User $actor, string $caseId, string $fileId): array
    {
        abort_if(! Str::isUuid($fileId), 404);
        $subjectId = $this->subjectId($actor, $caseId);
        $file = DB::transaction(function () use ($actor, $caseId, $fileId, $subjectId): \stdClass {
            $actor = $this->support->lock($actor, DisciplinePermissions::MANAGE, [$subjectId]);
            $case = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('public_id', $caseId)->first();
            abort_if(! $case instanceof \stdClass, 404);
            $this->assertMay($actor, $case->subject_user_id === null ? null : (int) $case->subject_user_id);
            $file = DB::table('hr_discipline_files')->where('case_id', $case->id)->where('public_id', $fileId)->first();
            abort_if(! $file instanceof \stdClass, 404);
            $this->support->audit->record('hr.discipline.file_opened', null, ['case_id' => (int) $case->id, 'file_id' => (int) $file->id], $actor, null, $actor->organisation_id);

            return $file;
        }, 3);
        $stored = Storage::disk(DocumentService::DISK)->exists($file->stored_path) ? Storage::disk(DocumentService::DISK)->get($file->stored_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR discipline file could not be decrypted', ['file_id' => $file->id]);
            abort(404);
        }
        $mime = ['pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$file->kind] ?? 'application/octet-stream';

        return ['content' => $content, 'mime' => $mime, 'inline' => $file->kind !== 'pdf',
            'filename' => 'discipline-'.substr((string) $file->public_id, 0, 8).'.'.($file->kind === 'jpeg' ? 'jpg' : $file->kind)];
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
     * One timeline entry under the case lock, with an idempotency key. $build returns the columns to add (it may write a
     * file first); $meta adds non-sensitive audit fields.
     *
     * @param  array<string, mixed>  $input
     * @param  list<mixed>  $hashParts
     * @param  callable(\stdClass): array<string, mixed>  $build
     * @param  (callable(): array<string, mixed>)|null  $meta
     */
    private function append(User $actor, string $caseId, array $input, string $kind, array $hashParts, callable $build, ?callable $meta = null): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $subjectId = $this->subjectId($actor, $caseId);
        DB::transaction(function () use ($actor, $caseId, $key, $kind, $hashParts, $build, $meta, $subjectId): void {
            $actor = $this->support->lock($actor, DisciplinePermissions::MANAGE, [$subjectId]);
            $hash = $this->support->hash([$actor->id, 'discipline_'.$kind, $caseId, ...$hashParts]);
            if ($this->support->replay($actor, $key, 'discipline_'.$kind, $hash) !== null) {
                return;
            }
            $case = $this->managed($actor, $caseId);
            $this->event($actor, (int) $case->id, $kind, $build($case));
            $this->support->remember($actor, $key, 'discipline_'.$kind, $hash, $caseId);
            $this->support->audit->record('hr.discipline.'.$kind.'_added', null, ['case_id' => (int) $case->id, ...($meta === null ? [] : $meta())], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $columns */
    private function event(User $actor, int $caseId, string $kind, array $columns = []): void
    {
        DB::table('hr_discipline_events')->insert(['organisation_id' => $actor->organisation_id, 'case_id' => $caseId, 'kind' => $kind,
            'actor_user_id' => $actor->id, 'created_at' => now()->utc(), ...$columns]);
    }

    /** The open case, locked, after the who-may rule. */
    private function managed(User $actor, string $caseId): \stdClass
    {
        $case = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('public_id', $caseId)->lockForUpdate()->first();
        abort_if(! $case instanceof \stdClass, 404);
        $this->assertMay($actor, $case->subject_user_id === null ? null : (int) $case->subject_user_id);
        if ($case->status !== 'open') {
            throw ValidationException::withMessages(['case' => 'This case is closed. Nothing more can be added.']);
        }

        return $case;
    }

    private function assertMay(User $actor, ?int $subjectUserId): void
    {
        $subject = $subjectUserId === null ? null : User::query()->whereKey($subjectUserId)->first();
        $refusal = $subjectUserId !== null && $subject === null ? 'This case cannot be opened.' : $this->refusalFor($actor, $subject);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    private function subjectId(User $actor, string $caseId): ?int
    {
        abort_if(! Str::isUuid($caseId), 404);
        $row = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('public_id', $caseId)->first(['subject_user_id']);
        abort_if(! $row instanceof \stdClass, 404);

        return $row->subject_user_id === null ? null : (int) $row->subject_user_id;
    }

    private function letter(\stdClass $case, string $letterId, string $type): \stdClass
    {
        $letter = DB::table('hr_letters')->where('organisation_id', $case->organisation_id)->where('public_id', $letterId)->sharedLock()->first();
        if (! $letter instanceof \stdClass || (int) $letter->worker_id !== (int) $case->worker_id || $letter->type !== $type || $letter->voided_at !== null) {
            throw ValidationException::withMessages(['letter_id' => $type === 'warning'
                ? 'Choose a current warning letter issued to this person in HR letters.' : 'Choose a current show-cause letter issued to this person in HR letters.']);
        }

        return $letter;
    }

    private static function text(mixed $value, string $field, int $min, int $max, string $message): string
    {
        $text = is_string($value) ? str_replace("\r\n", "\n", trim($value)) : '';
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $text;
    }

    private function pastDate(User $actor, mixed $value, string $field, string $message): string
    {
        $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
        $today = CarbonImmutable::now(self::zone((int) $actor->organisation_id))->toDateString();
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value || $value > $today || $value < '2000-01-01') {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }
}
