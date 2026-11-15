<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11c supervisor tasks (docs/teams/hr/HR-11-DESIGN.md section 7): one task per failed audit item, at the audited branch.
 * Open, then Done (a note and up to 3 photos of the fix), then, when the audit's rule says so, Verified by someone other
 * than the person who marked it done. A task can be cancelled with a fixed reason. No v1 role other than the audit
 * permission works a task, and nothing is ever sent.
 */
final class TaskService
{
    public const CANCEL_REASONS = ['done_elsewhere' => 'Done elsewhere', 'duplicate' => 'Duplicate', 'not_needed' => 'Not needed', 'other' => 'Other'];

    public function __construct(private ComplianceSupport $support, private CompliancePhotos $photos) {}

    /** @param  array<string, mixed>  $input */
    public function markDone(User $actor, string $taskId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $taskId = $this->uuid($taskId) ?? abort(404);
        $note = $this->support->text($input['note'] ?? null, 'note', 300);

        return DB::transaction(function () use ($actor, $key, $taskId, $note, $input): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $hash = $this->support->hash([$actor->id, 'task_done', $taskId, $note]);
            if (($replay = $this->support->replay($actor, $key, 'task_done', $hash)) !== null) {
                return $replay;
            }
            $task = $this->lockedTask($actor, $taskId);
            $this->support->assertLock($task, $input);
            if ($task->status !== 'open') {
                throw ValidationException::withMessages(['compliance' => 'This task is not open.']);
            }
            DB::table('compliance_tasks')->where('id', $task->id)->update(['status' => 'done', 'done_by_user_id' => $actor->id, 'done_at' => now()->utc(),
                'done_note_encrypted' => $note === null ? null : Crypt::encryptString($note), 'lock_version' => $task->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'task_done', $hash, (string) $task->public_id);
            $this->support->audit->record('compliance.task_done', null, ['task_id' => $task->id, 'audit_id' => $task->audit_id], $actor, null, $actor->organisation_id);

            return (string) $task->public_id;
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    public function verify(User $actor, string $taskId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $taskId = $this->uuid($taskId) ?? abort(404);

        return DB::transaction(function () use ($actor, $key, $taskId, $input): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $hash = $this->support->hash([$actor->id, 'task_verify', $taskId]);
            if (($replay = $this->support->replay($actor, $key, 'task_verify', $hash)) !== null) {
                return $replay;
            }
            $task = $this->lockedTask($actor, $taskId);
            $this->support->assertLock($task, $input);
            if ($task->status !== 'done' || ! $task->verify_required) {
                throw ValidationException::withMessages(['compliance' => 'This task is not waiting for verification.']);
            }
            if ((int) $task->done_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['compliance' => 'Someone other than the person who marked it done must verify it.']);
            }
            DB::table('compliance_tasks')->where('id', $task->id)->update(['status' => 'verified', 'verified_by_user_id' => $actor->id, 'verified_at' => now()->utc(),
                'lock_version' => $task->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'task_verify', $hash, (string) $task->public_id);
            $this->support->audit->record('compliance.task_verified', null, ['task_id' => $task->id, 'audit_id' => $task->audit_id], $actor, null, $actor->organisation_id);

            return (string) $task->public_id;
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    public function cancel(User $actor, string $taskId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $taskId = $this->uuid($taskId) ?? abort(404);
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        if (! array_key_exists($reason, self::CANCEL_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return DB::transaction(function () use ($actor, $key, $taskId, $reason, $input): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $hash = $this->support->hash([$actor->id, 'task_cancel', $taskId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'task_cancel', $hash)) !== null) {
                return $replay;
            }
            $task = $this->lockedTask($actor, $taskId);
            $this->support->assertLock($task, $input);
            if (! in_array($task->status, ['open', 'done'], true)) {
                throw ValidationException::withMessages(['compliance' => 'This task is already closed.']);
            }
            DB::table('compliance_tasks')->where('id', $task->id)->update(['status' => 'cancelled', 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now()->utc(),
                'cancel_reason' => $reason, 'lock_version' => $task->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'task_cancel', $hash, (string) $task->public_id);
            $this->support->audit->record('compliance.task_cancelled', null, ['task_id' => $task->id, 'audit_id' => $task->audit_id, 'reason' => $reason], $actor, null, $actor->organisation_id);

            return (string) $task->public_id;
        }, 3);
    }

    /**
     * One photo of the fix, on an open or done task (at most 3).
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $file
     */
    public function addPhoto(User $actor, string $taskId, array $file): string
    {
        $taskId = $this->uuid($taskId) ?? abort(404);
        $file = CompliancePhotos::imageOnly($file);
        $path = CompliancePhotos::newPath('tasks');
        $written = false;

        try {
            return DB::transaction(function () use ($actor, $taskId, $file, $path, &$written): string {
                $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
                $task = $this->lockedTask($actor, $taskId);
                if (! in_array($task->status, ['open', 'done'], true)) {
                    throw ValidationException::withMessages(['photo' => 'This task is closed.']);
                }
                $count = (int) DB::table('compliance_task_photos')->where('task_id', $task->id)->count();
                $live = (int) DB::table('compliance_task_photos')->where('task_id', $task->id)->whereNull('purged_at')->count();
                if ($live >= CompliancePhotos::MAX_PER_OWNER) {
                    throw ValidationException::withMessages(['photo' => 'Add at most 3 photos per task.']);
                }
                $this->photos->put($path, $file['content']);
                $written = true;
                $publicId = (string) Str::uuid();
                DB::table('compliance_task_photos')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'task_id' => $task->id, 'label' => 'Photo '.($count + 1),
                    'kind' => $file['kind'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256'], 'stored_path' => $path, 'added_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                $this->support->audit->record('compliance.task_photo_added', null, ['task_id' => $task->id], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            if ($written) {
                $this->photos->forget($path);
            }
            throw $e;
        }
    }

    /** Streams one task photo after the open is audited (perform at the task's branch, or the checklist manager). */
    public function openPhoto(User $actor, string $photoId): \stdClass
    {
        $photoId = $this->uuid($photoId) ?? abort(404);

        return DB::transaction(function () use ($actor, $photoId): \stdClass {
            $actor = $this->support->lockAny($actor, [CompliancePermissions::AUDITS_PERFORM, CompliancePermissions::CHECKLISTS_MANAGE]);
            $photo = DB::table('compliance_task_photos')->where('organisation_id', $actor->organisation_id)->where('public_id', $photoId)->first();
            abort_if($photo === null || $photo->purged_at !== null, 404);
            if (! $actor->can(CompliancePermissions::CHECKLISTS_MANAGE)) {
                $this->support->assertBranch($actor, (int) DB::table('compliance_tasks')->where('id', $photo->task_id)->value('branch_id'));
            }
            $this->support->audit->record('compliance.task_photo_opened', null, ['photo_id' => $photo->id, 'task_id' => $photo->task_id], $actor, null, $actor->organisation_id);

            return $photo;
        }, 3);
    }

    private function lockedTask(User $actor, string $taskId): \stdClass
    {
        $task = DB::table('compliance_tasks')->where('organisation_id', $actor->organisation_id)->where('public_id', $taskId)->lockForUpdate()->first();
        abort_if($task === null, 404);
        $this->support->assertBranch($actor, (int) $task->branch_id);

        return $task;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
