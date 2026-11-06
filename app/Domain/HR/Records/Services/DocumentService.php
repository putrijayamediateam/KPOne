<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Records\Models\Document;
use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
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
 * HR-4b document vault (HR-PLAN 11.3 rule 10). Files are checked by content and re-encoded as in HR-2b, encrypted on
 * the private disk with no original file name, and every open is recorded before any byte is sent. A removal keeps
 * the row and deletes only the bytes, after the removal commits. Retention is clinic-entered per category.
 */
final class DocumentService
{
    public const DISK = 'local';

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Days a first or shorter retention waits before the purge may use it (Suresh, HR-4b S1). */
    public const GRACE_DAYS = 7;

    public const CATEGORIES = ['contract' => 'Contract', 'offer' => 'Offer letter', 'identity' => 'Identity copy', 'certificate' => 'Certificate',
        'resignation' => 'Resignation letter', 'other' => 'Other'];

    public const REMOVAL_REASONS = ['wrong_file' => 'Wrong file', 'wrong_person' => 'Wrong person', 'not_readable' => 'Not readable'];

    public function __construct(private RecordSupport $support) {}

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared  from prepare()
     * @param  array<string, mixed>  $input
     */
    public function upload(User $actor, string $workerId, array $prepared, array $input): Document
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $category = $input['category'] ?? null;
        if (! is_string($category) || ! array_key_exists($category, self::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Choose the document type.']);
        }
        $issued = $this->optionalDate($input['issued_on'] ?? null, 'issued_on', 'Choose the issue date.');
        $expires = $this->optionalDate($input['expires_on'] ?? null, 'expires_on', 'Choose the expiry date.');
        if ($issued !== null && $expires !== null && $expires < $issued) {
            throw ValidationException::withMessages(['expires_on' => 'Choose an expiry date on or after the issue date.']);
        }
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $workerId, $prepared, $key, $category, $issued, $expires, &$written): Document {
                $this->forget($written);
                $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
                $hash = $this->support->hash([$actor->id, 'document_add', $workerId, $prepared['sha256'], $category, $issued, $expires]);
                if (($replay = $this->support->replay($actor, $key, 'records_document_add', $hash)) !== null) {
                    return Document::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
                }
                $worker = $this->support->lockWorker($actor, $workerId);
                $this->support->assertManages($actor, $worker);
                $document = $this->storeFor($actor, $worker, $prepared, $category, $issued, $expires, $written);
                $this->support->remember($actor, $key, 'records_document_add', $hash, $document->public_id);

                return $document;
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /**
     * Writes one checked file into a staff file and records it (event, change line, audit). The caller holds the
     * worker row lock inside its transaction and has checked who may add it; HR-5a uses this for credential evidence.
     * Paths written are appended to $written so the caller can delete them if the transaction rolls back.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared
     * @param  list<string>  $written
     */
    public function storeFor(User $actor, Worker $worker, array $prepared, string $category, ?string $issued, ?string $expires, array &$written): Document
    {
        if (Document::query()->where('worker_id', $worker->id)->where('status', 'active')->where('sha256', $prepared['sha256'])->exists()) {
            throw ValidationException::withMessages(['file' => 'This file is already in the staff file.']);
        }
        $publicId = (string) Str::uuid();
        $path = "hr-documents/{$actor->organisation_id}/{$publicId}.bin";
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
        }
        $written[] = $path;
        $document = new Document;
        $document->forceFill(['public_id' => $publicId, 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'category' => $category,
            'issued_on' => $issued, 'expires_on' => $expires, 'kind' => $prepared['kind'], 'bytes' => $prepared['bytes'], 'sha256' => $prepared['sha256'],
            'stored_path' => $path, 'status' => 'active', 'uploaded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
        $this->event($document, 'added', $actor);
        $this->support->change($actor, $worker, 'document', ['added', $category]);
        $this->support->audit->record('hr.records.document_added', $worker, ['document_id' => $document->id, 'category' => $category, 'kind' => $document->kind,
            'bytes' => $document->bytes], $actor, null, $actor->organisation_id);

        return $document;
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string} */
    public static function prepare(mixed $file): array
    {
        return LeaveEvidenceFiles::prepare($file, 'file', self::MAX_BYTES);
    }

    /** @param array<string, mixed> $input */
    public function remove(User $actor, string $workerId, string $documentId, array $input): Document
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REMOVAL_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is removed.']);
        }
        $removedPath = null;
        $document = DB::transaction(function () use ($actor, $workerId, $documentId, $input, $key, $reason, &$removedPath): Document {
            $removedPath = null;
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $hash = $this->support->hash([$actor->id, 'document_remove', $documentId, $reason]);
            if ($this->support->replay($actor, $key, 'records_document_remove', $hash) !== null) {
                return Document::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $documentId)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $document = $this->find($worker, $documentId, true);
            $this->support->assertLock($document, $input);
            if ($document->status !== 'active') {
                throw ValidationException::withMessages(['document' => 'This document was already removed. Reload the page.']);
            }
            $document->forceFill(['status' => 'removed', 'removed_by_user_id' => $actor->id, 'removed_at' => now()->utc(), 'removal_reason' => $reason,
                'lock_version' => $document->lock_version + 1])->save();
            $this->event($document, 'removed', $actor);
            $this->support->change($actor, $worker, 'document', ['removed', $document->category], $reason);
            $this->support->remember($actor, $key, 'records_document_remove', $hash, $document->public_id);
            $this->support->audit->record('hr.records.document_removed', $worker, ['document_id' => $document->id, 'reason_code' => $reason], $actor, null, $actor->organisation_id);
            $removedPath = $document->stored_path;

            return $document;
        }, 3);
        // The bytes go only once the removal is committed.
        if ($removedPath !== null && ! Storage::disk(self::DISK)->delete($removedPath)) {
            Log::warning('HR document file could not be deleted after removal', ['document_id' => $document->id]);
        }

        return $document;
    }

    /**
     * Records the open, then returns the decrypted bytes. The staff member opens their own documents; anyone else needs
     * the documents permission. Nothing is returned if the record cannot be written.
     *
     * @return array{content:string, mime:string, inline:bool, filename:string}
     */
    public function open(User $actor, string $workerId, string $documentId): array
    {
        $document = DB::transaction(function () use ($actor, $workerId, $documentId): Document {
            if (! Str::isUuid($workerId)) {
                abort(404);
            }
            $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $workerId)->firstOrFail();
            $own = $worker->user_id === $actor->id;
            $actor = $this->support->authority->lock($actor, $own ? RecordPermissions::VIEW_OWN : RecordPermissions::DOCUMENTS_VIEW);
            $document = $this->find($worker, $documentId, false);
            abort_if($document->status !== 'active', 404);
            $this->event($document, 'opened', $actor);
            $this->support->audit->record('hr.records.document_opened', $worker, ['document_id' => $document->id, 'own' => $own], $actor, null, $actor->organisation_id);

            return $document;
        }, 3);
        $stored = Storage::disk(self::DISK)->exists($document->stored_path) ? Storage::disk(self::DISK)->get($document->stored_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR document could not be decrypted', ['document_id' => $document->id]);
            abort(404);
        }

        return ['content' => $content, 'mime' => $document->mime(), 'inline' => $document->kind !== 'pdf',
            'filename' => $document->category.'-'.substr($document->public_id, 0, 8).'.'.($document->kind === 'jpeg' ? 'jpg' : $document->kind)];
    }

    /**
     * Saves a category's retention (Suresh, HR-4b S1). Clearing it ("Not set": keep everything) and lengthening it apply
     * at once, because they only keep more. A first or shorter retention could delete files, so it waits as pending for
     * GRACE_DAYS before the purge uses it; saving again in that time replaces or cancels it.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveRetention(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $category = $input['category'] ?? null;
        if (! is_string($category) || ! array_key_exists($category, self::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Choose the document type.']);
        }
        $raw = $input['retention_days'] ?? null;
        $days = $raw === null || $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]);
        if ($days === false) {
            throw ValidationException::withMessages(['retention_days' => 'Enter a whole number of days from 1 to 36500, or leave it empty for Not set.']);
        }
        DB::transaction(function () use ($actor, $input, $key, $category, $days): void {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            // A Director and an HR Manager hold different locks; serialise saves of one category (Suresh, M3).
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_retention:'.$actor->organisation_id.':'.$category]);
            $hash = $this->support->hash([$actor->id, 'retention', $category, $days]);
            if ($this->support->replay($actor, $key, 'records_retention', $hash) !== null) {
                return;
            }
            $row = DB::table('hr_document_retentions')->where('organisation_id', $actor->organisation_id)->where('category', $category)->lockForUpdate()->first();
            if ($row !== null) {
                $this->support->assertLock($row, $input);
            }
            $today = $this->today($actor->organisation_id);
            $current = $row === null ? null : self::effectiveDays($row, $today);
            $values = match (true) {
                $days === null => ['retention_days' => null, 'pending_days' => null, 'pending_from' => null],
                $current !== null && $days >= $current => ['retention_days' => $days, 'pending_days' => null, 'pending_from' => null],
                default => ['retention_days' => $current, 'pending_days' => $days, 'pending_from' => CarbonImmutable::parse($today)->addDays(self::GRACE_DAYS)->toDateString()],
            };
            if ($row !== null) {
                DB::table('hr_document_retentions')->where('id', $row->id)->update([...$values, 'set_by_user_id' => $actor->id,
                    'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now()->utc()]);
            } else {
                DB::table('hr_document_retentions')->insert(['organisation_id' => $actor->organisation_id, 'category' => $category, ...$values,
                    'set_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'records_retention', $hash, $category);
            $this->support->audit->record('hr.records.retention_set', null, ['category' => $category, 'retention_days' => $days,
                'pending_from' => $values['pending_from']], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** The retention the purge uses on a date: a pending one once its date has come, otherwise the current one. */
    public static function effectiveDays(object $row, string $today): ?int
    {
        $pendingFrom = data_get($row, 'pending_from');
        if (data_get($row, 'pending_days') !== null && is_string($pendingFrom) && substr($pendingFrom, 0, 10) <= $today) {
            return (int) data_get($row, 'pending_days');
        }
        $days = data_get($row, 'retention_days');

        return $days === null ? null : (int) $days;
    }

    private function today(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->toDateString();
    }

    /**
     * Daily: deletes the bytes of documents whose category retention has passed. Retention counts from the end of the
     * worker's latest employment period, so a current staff member's documents are kept; with no retention set for a
     * category, nothing of it is deleted. Rows and events stay.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        Document::query()->where('status', 'active')->select('id')->chunkById(200, function ($chunk) use (&$count): void {
            foreach ($chunk as $candidate) {
                $path = DB::transaction(function () use ($candidate): ?string {
                    $workerId = Document::query()->whereKey($candidate->id)->value('worker_id');
                    if ($workerId === null) {
                        return null;
                    }
                    // The worker row, then the document (the upload and remove order).
                    $worker = Worker::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
                    /** @var Document $document */
                    $document = Document::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if ($document->status !== 'active' || ! $this->due($worker, $document)) {
                        return null;
                    }
                    $document->forceFill(['status' => 'purged', 'removed_at' => now()->utc(), 'lock_version' => $document->lock_version + 1])->save();
                    $this->event($document, 'purged', null);
                    $this->support->audit->record('hr.records.document_purged', $worker, ['document_id' => $document->id], null, null, $worker->organisation_id);

                    return $document->stored_path;
                }, 3);
                if ($path !== null) {
                    if (! Storage::disk(self::DISK)->delete($path)) {
                        Log::warning('HR document file could not be deleted by the purge', ['document_id' => $candidate->id]);
                    }
                    $count++;
                }
            }
        });

        return $count;
    }

    private function due(Worker $worker, Document $document): bool
    {
        $today = $this->today($worker->organisation_id);
        $row = DB::table('hr_document_retentions')->where('organisation_id', $worker->organisation_id)->where('category', $document->category)->first();
        $days = $row === null ? null : self::effectiveDays($row, $today);
        if ($days === null) {
            return false;
        }
        $latest = EmploymentPeriod::query()->where('worker_id', $worker->id)->orderByDesc('starts_on')->first();
        if ($latest === null || $latest->ends_on === null) {
            return false;
        }

        return $latest->ends_on->addDays($days)->toDateString() < $today;
    }

    private function find(Worker $worker, string $documentId, bool $lock): Document
    {
        if (! Str::isUuid($documentId)) {
            abort(404);
        }
        $query = Document::query()->where('worker_id', $worker->id)->where('public_id', $documentId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function event(Document $document, string $event, ?User $actor): void
    {
        DB::table('hr_document_events')->insert(['organisation_id' => $document->organisation_id, 'document_id' => $document->id, 'event' => $event,
            'actor_user_id' => $actor?->id, 'created_at' => now()->utc()]);
    }

    private function optionalDate(mixed $value, string $field, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    /** @param list<string> $paths */
    public function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
        $paths = [];
    }
}
