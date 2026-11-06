<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveEvidenceFile;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-2b leave evidence files (owner, 2026-11-06). The applicant adds files to their own request while it waits, or up
 * to 14 days after approved leave ends; a leave manager adds or removes on anyone's behalf. Only the applicant and
 * holders of hr.leave.evidence.view.organisation open a file, and every open is recorded before a byte is sent.
 * Files are encrypted on the private disk under a generated name; the original name is never kept. Audit holds ids,
 * the type code, a count and a size, never content.
 */
final class LeaveEvidenceService
{
    public const DISK = 'local';

    public const MAX_FILES = 3;

    public const ADD_DAYS_AFTER_END = 14;

    public const CLOSED_REQUEST_DAYS = 30;

    public const REMOVAL_REASONS = ['wrong_file' => 'Wrong file', 'wrong_person' => 'Wrong person', 'not_readable' => 'Not readable'];

    public function __construct(private LeaveSupport $support) {}

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared  from LeaveEvidenceFiles::prepare
     * @param  array<string, mixed>  $input
     */
    public function add(User $actor, string $requestId, array $prepared, array $input, string $field = 'file'): LeaveEvidenceFile
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $requestId, $prepared, $key, $field, &$written): LeaveEvidenceFile {
                // A retried transaction must not leave the previous attempt's file behind.
                $this->forget($written);
                $applicant = $this->applicantId($actor, $requestId);
                $own = $applicant === $actor->id;
                $actor = $this->support->lock($actor, $own ? LeavePermissions::REQUEST_OWN : LeavePermissions::MANAGE, [$applicant]);
                $hash = $this->support->hash([$actor->id, 'evidence_add', $requestId, $prepared['sha256']]);
                if (($replay = $this->support->replay($actor, $key, 'leave_evidence_add', $hash)) !== null) {
                    return LeaveEvidenceFile::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
                }
                $request = $this->lockRequest($actor, $requestId);
                $type = LeaveType::query()->whereKey($request->leave_type_id)->firstOrFail();
                if (! $type->accepts_evidence_files) {
                    throw ValidationException::withMessages([$field => "{$type->name} does not take evidence files."]);
                }
                if (! in_array($request->status, ['pending', 'approved'], true)) {
                    throw ValidationException::withMessages([$field => 'Files can no longer be added to this request.']);
                }
                if ($own && $request->status === 'approved') {
                    $today = CarbonImmutable::now($this->branch($request)->timezone)->toDateString();
                    if ($today > CarbonImmutable::parse($request->end_date->toDateString())->addDays(self::ADD_DAYS_AFTER_END)->toDateString()) {
                        throw ValidationException::withMessages([$field => 'Files can no longer be added to this request.']);
                    }
                }
                $active = LeaveEvidenceFile::query()->where('leave_request_id', $request->id)->where('status', 'active')->lockForUpdate()->get(['id', 'sha256']);
                if ($active->contains(fn ($f): bool => $f->sha256 === $prepared['sha256'])) {
                    throw ValidationException::withMessages([$field => 'This file is already attached.']);
                }
                if ($active->count() >= self::MAX_FILES) {
                    throw ValidationException::withMessages([$field => 'This request already has 3 files.']);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-leave-evidence/{$actor->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                    throw ValidationException::withMessages([$field => 'The file could not be saved. Try again.']);
                }
                $written[] = $path;
                $file = new LeaveEvidenceFile;
                $file->forceFill(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'leave_request_id' => $request->id,
                    'uploaded_by_user_id' => $actor->id, 'on_behalf' => ! $own, 'kind' => $prepared['kind'], 'bytes' => $prepared['bytes'],
                    'sha256' => $prepared['sha256'], 'stored_path' => $path, 'status' => 'active'])->save();
                $this->event($file, 'added', $actor);
                $this->support->remember($actor, $key, 'leave_evidence_add', $hash, $publicId);
                $this->support->audit->record('hr.leave.evidence_added', $request, ['evidence_file_id' => $file->id, 'leave_type_code' => $type->code,
                    'kind' => $file->kind, 'bytes' => $file->bytes, 'on_behalf' => ! $own, 'active_file_count' => $active->count() + 1], $actor, $this->branch($request));

                return $file;
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    public function remove(User $actor, string $fileId, array $input): LeaveEvidenceFile
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $removedPath = null;
        $file = DB::transaction(function () use ($actor, $fileId, $input, $key, &$removedPath): LeaveEvidenceFile {
            $removedPath = null;
            $found = $this->findFile($actor, $fileId);
            $applicant = $found === null ? null : (int) LeaveRequest::query()->whereKey($found->leave_request_id)->value('user_id');
            $own = $applicant === $actor->id;
            $actor = $this->support->lock($actor, $own ? LeavePermissions::REQUEST_OWN : LeavePermissions::MANAGE, [$applicant]);
            $reason = $own ? 'withdrawn' : ($input['reason'] ?? null);
            if (! $own && (! is_string($reason) || ! array_key_exists($reason, self::REMOVAL_REASONS))) {
                throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
            }
            $hash = $this->support->hash([$actor->id, 'evidence_remove', $fileId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'leave_evidence_remove', $hash)) !== null) {
                return LeaveEvidenceFile::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            abort_if($found === null, 404);
            $request = LeaveRequest::query()->whereKey($found->leave_request_id)->lockForUpdate()->firstOrFail();
            /** @var LeaveEvidenceFile $file */
            $file = LeaveEvidenceFile::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            $this->support->assertLock($file, $input);
            if ($file->status !== 'active') {
                throw ValidationException::withMessages(['leave' => 'This file was already removed. Reload the page.']);
            }
            if ($own && $request->status !== 'pending') {
                throw ValidationException::withMessages(['leave' => 'This request was decided. Ask HR to remove the file.']);
            }
            $file->forceFill(['status' => 'removed', 'removed_by_user_id' => $actor->id, 'removed_at' => now()->utc(), 'removal_reason' => $reason,
                'lock_version' => $file->lock_version + 1])->save();
            $this->event($file, 'removed', $actor);
            $this->support->remember($actor, $key, 'leave_evidence_remove', $hash, $file->public_id);
            $this->support->audit->record('hr.leave.evidence_removed', $request, ['evidence_file_id' => $file->id, 'reason_code' => $reason, 'by_applicant' => $own],
                $actor, $this->branch($request));
            $removedPath = $file->stored_path;

            return $file;
        }, 3);
        // The stored file is deleted only once the removal is committed.
        if ($removedPath !== null) {
            Storage::disk(self::DISK)->delete($removedPath);
        }

        return $file;
    }

    /**
     * Records the open, then returns the decrypted bytes. The applicant opens their own files; anyone else needs the
     * evidence view permission. Nothing is returned if the record cannot be written.
     *
     * @return array{content:string, mime:string, inline:bool, filename:string}
     */
    public function open(User $actor, string $fileId): array
    {
        $file = DB::transaction(function () use ($actor, $fileId): LeaveEvidenceFile {
            $file = $this->findFile($actor, $fileId);
            abort_if($file === null || $file->status !== 'active', 404);
            $request = LeaveRequest::query()->whereKey($file->leave_request_id)->firstOrFail();
            $own = $request->user_id === $actor->id;
            $actor = $this->support->authority->lock($actor, $own ? LeavePermissions::REQUEST_OWN : LeavePermissions::EVIDENCE_VIEW);
            $this->event($file, 'opened', $actor);
            $this->support->audit->record('hr.leave.evidence_opened', $request, ['evidence_file_id' => $file->id, 'by_applicant' => $own], $actor, $this->branch($request));

            return $file;
        }, 3);
        $stored = Storage::disk(self::DISK)->exists($file->stored_path) ? Storage::disk(self::DISK)->get($file->stored_path) : null;
        abort_if(! is_string($stored), 404);

        return ['content' => Crypt::decryptString($stored), 'mime' => $file->mime(), 'inline' => $file->kind !== 'pdf',
            'filename' => 'leave-evidence-'.substr($file->public_id, 0, 8).'.'.($file->kind === 'jpeg' ? 'jpg' : $file->kind)];
    }

    /**
     * Daily: deletes each active file once its leave type's retention after the leave's end date has passed, and files
     * of a rejected or cancelled request 30 days after that decision. Rows and the request's history stay.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        $candidates = LeaveEvidenceFile::query()->where('status', 'active')->with('request')->orderBy('id')->get();
        foreach ($candidates as $candidate) {
            $request = $candidate->request;
            $type = LeaveType::query()->whereKey($request->leave_type_id)->first();
            $zone = (string) (Branch::query()->whereKey($request->branch_id)->value('timezone') ?? 'UTC');
            $today = CarbonImmutable::now($zone)->toDateString();
            if (in_array($request->status, ['rejected', 'cancelled'], true)) {
                $closedAt = $request->status === 'cancelled' ? $request->cancelled_at : $request->decided_at;
                $due = $closedAt !== null && CarbonImmutable::parse($closedAt)->setTimezone($zone)->addDays(self::CLOSED_REQUEST_DAYS)->toDateString() <= $today;
            } else {
                $days = $type?->evidence_retention_days;
                $due = $days !== null && CarbonImmutable::parse($request->end_date->toDateString())->addDays($days)->toDateString() < $today;
            }
            if (! $due) {
                continue;
            }
            $path = DB::transaction(function () use ($candidate): ?string {
                /** @var LeaveEvidenceFile|null $file */
                $file = LeaveEvidenceFile::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if ($file === null || $file->status !== 'active') {
                    return null;
                }
                $file->forceFill(['status' => 'purged', 'removed_at' => now()->utc(), 'lock_version' => $file->lock_version + 1])->save();
                $this->event($file, 'purged', null);
                $this->support->audit->record('hr.leave.evidence_purged', $file, ['evidence_file_id' => $file->id], null, organisationId: $file->organisation_id);

                return $file->stored_path;
            }, 3);
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
                $count++;
            }
        }

        return $count;
    }

    private function findFile(User $actor, string $fileId): ?LeaveEvidenceFile
    {
        if (! Str::isUuid($fileId)) {
            return null;
        }

        return LeaveEvidenceFile::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $fileId)->first();
    }

    private function applicantId(User $actor, string $requestId): ?int
    {
        if (! Str::isUuid($requestId)) {
            return null;
        }
        $id = LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    private function lockRequest(User $actor, string $requestId): LeaveRequest
    {
        abort_unless(Str::isUuid($requestId), 404);

        return LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $requestId)->lockForUpdate()->firstOrFail();
    }

    private function branch(LeaveRequest $request): Branch
    {
        return Branch::query()->whereKey($request->branch_id)->firstOrFail();
    }

    private function event(LeaveEvidenceFile $file, string $action, ?User $actor): void
    {
        DB::table('hr_leave_evidence_events')->insert(['organisation_id' => $file->organisation_id, 'evidence_file_id' => $file->id, 'action' => $action,
            'actor_user_id' => $actor?->id, 'occurred_at' => now()->utc()]);
    }

    /** @param list<string> $paths */
    private function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
        $paths = [];
    }
}
