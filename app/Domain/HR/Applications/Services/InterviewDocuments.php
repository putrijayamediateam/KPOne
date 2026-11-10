<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-17d interview documents (HR-PLAN 19.7). HR uploads PDFs per position type, at most 10 MB each and 10 active per
 * type, encrypted on the private disk under a generated name with no original file name. The candidate downloads them
 * from their private page only while their interview is confirmed; HR opens them from Settings. Every upload, open,
 * removal and download is an append-only event, written before any byte is sent. A removal keeps the row and deletes
 * the bytes. Audit holds ids and codes only.
 */
final class InterviewDocuments
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PER_TYPE = 10;

    public function __construct(private RecordSupport $support) {}

    /**
     * @param  array<string, mixed>  $input  position_type, title, idempotency_key
     */
    public function upload(User $actor, mixed $file, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $type = is_string($input['position_type'] ?? null) && isset(JobApplicationService::TYPES[$input['position_type']]) ? (string) $input['position_type'] : null;
        if ($type === null) {
            throw ValidationException::withMessages(['position_type' => 'Choose a position type.']);
        }
        $title = self::title($input['title'] ?? null);
        $prepared = self::prepare($file);
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $key, $type, $title, $prepared, &$path): string {
                $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
                $hash = $this->support->hash([$actor->id, 'interview_document_upload', $type, $title, $prepared['sha256']]);
                if (($replay = $this->support->replay($actor, $key, 'interview_document_upload', $hash)) !== null) {
                    return $replay;
                }
                // Two uploads for one type wait for each other, so the count of ten holds.
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hr_interview_documents:{$actor->organisation_id}:{$type}"]);
                $active = DB::table('hr_interview_documents')->where('organisation_id', $actor->organisation_id)->where('position_type', $type)->where('status', 'active');
                if ((clone $active)->count() >= self::MAX_PER_TYPE) {
                    throw ValidationException::withMessages(['file' => 'This position type already has '.self::MAX_PER_TYPE.' documents. Remove one first.']);
                }
                if ((clone $active)->where('sha256', $prepared['sha256'])->exists()) {
                    throw ValidationException::withMessages(['file' => 'This file is already listed for this position type.']);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-interview-documents/{$actor->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(JobApplicationIntake::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                    $path = null;
                    throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
                }
                $now = now()->utc();
                $id = DB::table('hr_interview_documents')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                    'position_type' => $type, 'title' => $title, 'stored_path' => $path, 'bytes' => $prepared['bytes'], 'sha256' => $prepared['sha256'],
                    'status' => 'active', 'uploaded_by_user_id' => $actor->id, 'uploaded_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $this->event($actor->organisation_id, $id, 'uploaded', $actor->id);
                $this->support->remember($actor, $key, 'interview_document_upload', $hash, $publicId);
                $this->support->audit->record('hr.applications.interview_document_uploaded', null, ['document_id' => $id, 'position_type' => $type, 'bytes' => $prepared['bytes']],
                    $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            // The row never committed: its bytes go too.
            if ($path !== null) {
                Storage::disk(JobApplicationIntake::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input  expected_lock_version, idempotency_key
     */
    public function remove(User $actor, string $documentId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $removed = null;
        DB::transaction(function () use ($actor, $documentId, $input, $key, &$removed): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'interview_document_remove', $documentId]);
            if ($this->support->replay($actor, $key, 'interview_document_remove', $hash) !== null) {
                return;
            }
            $document = $this->find($actor, $documentId, true);
            if ($document->status !== 'active') {
                throw ValidationException::withMessages(['document' => 'This document was already removed.']);
            }
            $this->support->assertLock($document, $input);
            DB::table('hr_interview_documents')->where('id', $document->id)->update(['status' => 'removed', 'stored_path' => null,
                'removed_by_user_id' => $actor->id, 'removed_at' => now()->utc(), 'lock_version' => (int) $document->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->event($actor->organisation_id, (int) $document->id, 'removed', $actor->id);
            $this->support->remember($actor, $key, 'interview_document_remove', $hash, $documentId);
            $this->support->audit->record('hr.applications.interview_document_removed', null, ['document_id' => (int) $document->id], $actor, null, $actor->organisation_id);
            $removed = (string) $document->stored_path;
        }, 3);
        // The bytes go after the commit; a failed delete is logged and the row already says removed.
        if ($removed !== null && ! Storage::disk(JobApplicationIntake::DISK)->delete($removed)) {
            Log::warning('HR interview document bytes could not be deleted', ['path_hash' => hash('sha256', $removed)]);
        }
    }

    /**
     * HR opens a document; the open is recorded first.
     *
     * @return array{content:string, filename:string}
     */
    public function open(User $actor, string $documentId): array
    {
        $document = DB::transaction(function () use ($actor, $documentId): \stdClass {
            $actor = $this->support->authority->lock($actor, ApplicationPermissions::MANAGE);
            $document = $this->find($actor, $documentId, false);
            if ($document->status !== 'active') {
                throw ValidationException::withMessages(['document' => 'This document was removed.']);
            }
            $this->event($actor->organisation_id, (int) $document->id, 'opened', $actor->id);
            $this->support->audit->record('hr.applications.interview_document_opened', null, ['document_id' => (int) $document->id], $actor, null, $actor->organisation_id);

            return $document;
        }, 3);

        return $this->read($document);
    }

    /**
     * The candidate downloads a document through their private link, while their interview is confirmed. The download
     * is recorded first; PostgreSQL refuses the record otherwise.
     *
     * @return array{content:string, filename:string}|null null when the link, the stage or the document does not allow it
     */
    public function download(mixed $token, string $documentId, CandidateLink $links): ?array
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }
        $document = DB::transaction(function () use ($token, $documentId, $links): ?\stdClass {
            $application = $links->resolve($token, true);
            if ($application === null) {
                return null;
            }
            $document = collect(self::forApplication($application))->firstWhere('public_id', $documentId);
            if (! $document instanceof \stdClass) {
                return null;
            }
            $this->event((int) $application->organisation_id, (int) $document->id, 'downloaded', null, (int) $application->id);

            return $document;
        }, 3);

        return $document === null ? null : $this->read($document);
    }

    /**
     * The active documents a candidate may download: their position type, while the interview is confirmed.
     *
     * @return list<\stdClass>
     */
    public static function forApplication(\stdClass $application): array
    {
        if ($application->status !== 'interview' || $application->purged_at !== null
            || ! DB::table('hr_job_interviews')->where('application_id', $application->id)->where('status', 'confirmed')->exists()) {
            return [];
        }
        $type = DB::table('hr_job_positions')->where('id', $application->position_id)->value('position_type');

        return array_values(DB::table('hr_interview_documents')->where('organisation_id', $application->organisation_id)->where('position_type', $type)
            ->where('status', 'active')->orderBy('title')->orderBy('id')->get(['id', 'public_id', 'title', 'bytes', 'stored_path'])->all());
    }

    /**
     * What the candidate's page lists: title and size only.
     *
     * @return list<array{id:string, title:string, size:string}>
     */
    public static function candidateList(\stdClass $application): array
    {
        return array_map(fn (\stdClass $d): array => ['id' => (string) $d->public_id, 'title' => (string) $d->title, 'size' => self::size((int) $d->bytes)],
            self::forApplication($application));
    }

    /**
     * HR's drawer: which of the candidate's documents they have downloaded, and when first.
     *
     * @return list<array{title:string, downloadedAt:string|null}>
     */
    public static function downloadsFor(\stdClass $application, string $zone): array
    {
        $type = DB::table('hr_job_positions')->where('id', $application->position_id)->value('position_type');
        $first = DB::table('hr_interview_document_events')->where('application_id', $application->id)->where('action', 'downloaded')
            ->groupBy('document_id')->selectRaw('document_id, min(created_at) as first_at')->pluck('first_at', 'document_id');
        $docs = DB::table('hr_interview_documents')->where('organisation_id', $application->organisation_id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('position_type', $type)->where('status', 'active'))->orWhereIn('id', $first->keys()))
            ->orderBy('title')->orderBy('id')->get(['id', 'title']);

        return array_values($docs->map(fn (\stdClass $d): array => ['title' => (string) $d->title,
            'downloadedAt' => isset($first[$d->id]) ? CarbonImmutable::parse((string) $first[$d->id])->setTimezone($zone)->format('j M Y, g:i A') : null])->all());
    }

    /**
     * Settings: the documents per position type.
     *
     * @return list<array{type:string, label:string, documents:list<array<string, mixed>>}>
     */
    public static function settings(int $organisationId, string $zone): array
    {
        $rows = DB::table('hr_interview_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_user_id')
            ->where('d.organisation_id', $organisationId)->where('d.status', 'active')->orderBy('d.title')->orderBy('d.id')
            ->get(['d.public_id', 'd.position_type', 'd.title', 'd.bytes', 'd.uploaded_at', 'd.lock_version', 'u.name as uploader']);
        $downloads = DB::table('hr_interview_document_events as e')->join('hr_interview_documents as d', 'd.id', '=', 'e.document_id')
            ->where('e.organisation_id', $organisationId)->where('e.action', 'downloaded')->groupBy('d.public_id')
            ->selectRaw('d.public_id, count(distinct e.application_id) as candidates')->pluck('candidates', 'public_id');

        return array_map(fn (string $label, string $type): array => ['type' => $type, 'label' => $label,
            'documents' => array_values($rows->where('position_type', $type)->map(fn (\stdClass $d): array => [
                'id' => (string) $d->public_id, 'title' => (string) $d->title, 'size' => self::size((int) $d->bytes),
                'uploadedOn' => CarbonImmutable::parse($d->uploaded_at)->setTimezone($zone)->format('j M Y'), 'uploadedBy' => (string) ($d->uploader ?? 'Staff member'),
                'candidates' => (int) ($downloads[$d->public_id] ?? 0), 'lockVersion' => (int) $d->lock_version])->all())],
            JobApplicationService::TYPES, array_keys(JobApplicationService::TYPES));
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string} a real PDF of at most 10 MB, checked by content */
    public static function prepare(mixed $file): array
    {
        $notPdf = 'Use a PDF file.';
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a PDF file.']);
        }
        if ((string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => $notPdf]);
        }
        $prepared = LeaveEvidenceFiles::prepare($file, 'file', self::MAX_BYTES);
        if ($prepared['kind'] !== 'pdf') {
            throw ValidationException::withMessages(['file' => $notPdf]);
        }

        return $prepared;
    }

    public static function title(mixed $value): string
    {
        $title = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || preg_match('/[<>{}]/u', $title) === 1) {
            throw ValidationException::withMessages(['title' => 'Give the document a title of 3 to 80 characters, without < > { }.']);
        }

        return $title;
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? number_format($bytes / (1024 * 1024), 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** @return array{content:string, filename:string} */
    private function read(\stdClass $document): array
    {
        $path = (string) $document->stored_path;
        $stored = Storage::disk(JobApplicationIntake::DISK)->exists($path) ? Storage::disk(JobApplicationIntake::DISK)->get($path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR interview document could not be decrypted', ['document_id' => $document->id]);
            abort(404);
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii((string) $document->title))), '-');

        return ['content' => $content, 'filename' => ($slug !== '' ? mb_substr($slug, 0, 60) : 'interview-document').'.pdf'];
    }

    private function find(User $actor, string $documentId, bool $lock): \stdClass
    {
        if (! Str::isUuid($documentId)) {
            abort(404);
        }
        $query = DB::table('hr_interview_documents')->where('organisation_id', $actor->organisation_id)->where('public_id', $documentId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if(! $row instanceof \stdClass, 404);

        return $row;
    }

    private function event(int $organisationId, int $documentId, string $action, ?int $actorId, ?int $applicationId = null): void
    {
        DB::table('hr_interview_document_events')->insert(['organisation_id' => $organisationId, 'document_id' => $documentId,
            'application_id' => $applicationId, 'action' => $action, 'actor_user_id' => $actorId, 'created_at' => now()->utc()]);
    }
}
