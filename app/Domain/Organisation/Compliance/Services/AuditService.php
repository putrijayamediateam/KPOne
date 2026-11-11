<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11c monthly audits (docs/teams/hr/HR-11-DESIGN.md section 7). One audit is one checklist version, one branch and one
 * branch-local month. A supervisor answers each item (pass, fail or not applicable), adds a short note and up to 3 photos,
 * and submits; submitting is final and writes one task per failed item in the same transaction. A record of what a person
 * typed, not a certification. Audit rows hold ids and codes only: never a note.
 */
final class AuditService
{
    public const RESULTS = ['pass', 'fail', 'na'];

    public function __construct(private ComplianceSupport $support, private CompliancePhotos $photos) {}

    /** The first day of the branch-local month: this one, or last month for a late audit. */
    public static function monthStart(string $zone, string $which): CarbonImmutable
    {
        $now = CarbonImmutable::now($zone)->startOfMonth();

        return $which === 'previous' ? $now->subMonth() : $now;
    }

    /**
     * Starts an audit for a branch and month (this month, or last month when none exists).
     *
     * @param  array<string, mixed>  $input
     */
    public function start(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $branchId = filter_var($input['branch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $which = ($input['month'] ?? 'current') === 'previous' ? 'previous' : 'current';
        if ($branchId === false) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
        }

        return DB::transaction(function () use ($actor, $key, $branchId, $which): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $this->support->assertBranch($actor, $branchId);
            $branch = DB::table('branches')->where('id', $branchId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->first();
            if ($branch === null) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
            }
            $month = self::monthStart((string) $branch->timezone, $which)->toDateString();
            $hash = $this->support->hash([$actor->id, 'audit_start', $branchId, $month]);
            if (($replay = $this->support->replay($actor, $key, 'audit_start', $hash)) !== null) {
                return $replay;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_audit:'.$branchId.':'.$month]);
            if (DB::table('compliance_audits')->where('branch_id', $branchId)->whereDate('month_start', $month)->exists()) {
                throw ValidationException::withMessages(['compliance' => 'An audit already exists for this branch and month.']);
            }
            $version = DB::table('compliance_checklist_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
            if ($version === null) {
                throw ValidationException::withMessages(['compliance' => 'There is no checklist yet. Ask the Director or Administrator to add it under Settings.']);
            }
            $verify = (bool) (DB::table('compliance_settings')->where('organisation_id', $actor->organisation_id)->value('verify_done_tasks') ?? true);
            $publicId = (string) Str::uuid();
            DB::table('compliance_audits')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branchId, 'checklist_version_id' => $version->id,
                'month_start' => $month, 'status' => 'open', 'started_by_user_id' => $actor->id, 'started_at' => now()->utc(), 'verify_done_tasks' => $verify, 'lock_version' => 1,
                'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            $id = (int) DB::table('compliance_audits')->where('public_id', $publicId)->value('id');
            $this->support->remember($actor, $key, 'audit_start', $hash, $publicId);
            $this->support->audit->record('compliance.audit_started', null, ['audit_id' => $id, 'branch_id' => $branchId, 'checklist_version' => $version->version], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Saves answers while the audit is open. Each: item_id, result, note (required for a fail).
     *
     * @param  array<string, mixed>  $input
     */
    public function saveAnswers(User $actor, string $auditId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $auditId = $this->uuid($auditId) ?? abort(404);
        $rows = $input['answers'] ?? null;
        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages(['answers' => 'Answer at least one item.']);
        }
        $clean = [];
        foreach ($rows as $i => $row) {
            $itemId = is_array($row) ? $this->uuid($row['item_id'] ?? null) : null;
            $result = is_array($row) && is_string($row['result'] ?? null) ? $row['result'] : '';
            if ($itemId === null || ! in_array($result, self::RESULTS, true)) {
                throw ValidationException::withMessages(["answers.$i.result" => 'Choose Pass, Fail or Not applicable.']);
            }
            $note = $this->support->text($row['note'] ?? null, "answers.$i.note", 300, $result === 'fail');
            $clean[$itemId] = ['result' => $result, 'note' => $note];
        }

        return DB::transaction(function () use ($actor, $key, $auditId, $input, $clean): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $hash = $this->support->hash([$actor->id, 'audit_answers', $auditId, $clean]);
            if (($replay = $this->support->replay($actor, $key, 'audit_answers', $hash)) !== null) {
                return $replay;
            }
            $audit = $this->lockedAudit($actor, $auditId);
            $this->support->assertLock($audit, $input);
            $items = DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->whereIn('public_id', array_keys($clean))->pluck('id', 'public_id');
            if ($items->count() !== count($clean)) {
                throw ValidationException::withMessages(['answers' => 'One of these items is not on this audit. Reload the page.']);
            }
            foreach ($clean as $itemPublic => $answer) {
                $itemId = (int) $items[$itemPublic];
                DB::table('compliance_audit_answers')->updateOrInsert(['audit_id' => $audit->id, 'item_id' => $itemId], ['organisation_id' => $actor->organisation_id, 'result' => $answer['result'],
                    'note_encrypted' => $answer['note'] === null ? null : Crypt::encryptString($answer['note']), 'answered_at' => now()->utc()]);
            }
            DB::table('compliance_audits')->where('id', $audit->id)->update(['lock_version' => $audit->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'audit_answers', $hash, (string) $audit->public_id);
            $this->support->audit->record('compliance.audit_answers_saved', null, ['audit_id' => $audit->id, 'count' => count($clean)], $actor, null, $actor->organisation_id);

            return (string) $audit->public_id;
        }, 3);
    }

    /**
     * Adds one photo to an item of an open audit (at most 3 per item). The photo is encrypted on the private disk.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $file
     */
    public function addPhoto(User $actor, string $auditId, string $itemId, array $file): string
    {
        $auditId = $this->uuid($auditId) ?? abort(404);
        $itemId = $this->uuid($itemId) ?? abort(404);
        $file = CompliancePhotos::imageOnly($file);
        $path = CompliancePhotos::newPath('audits');
        $written = false;

        try {
            return DB::transaction(function () use ($actor, $auditId, $itemId, $file, $path, &$written): string {
                $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
                $audit = $this->lockedAudit($actor, $auditId);
                $item = DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->where('public_id', $itemId)->first();
                abort_if($item === null, 404);
                $all = DB::table('compliance_audit_photos')->where('audit_id', $audit->id)->where('item_id', $item->id);
                $count = (int) (clone $all)->count();
                $live = (int) (clone $all)->whereNull('purged_at')->count();
                if ($live >= CompliancePhotos::MAX_PER_OWNER) {
                    throw ValidationException::withMessages(['photo' => 'Add at most 3 photos per item.']);
                }
                $this->photos->put($path, $file['content']);
                $written = true;
                $publicId = (string) Str::uuid();
                DB::table('compliance_audit_photos')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'audit_id' => $audit->id, 'item_id' => $item->id,
                    'label' => 'Photo '.($count + 1), 'kind' => $file['kind'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256'], 'stored_path' => $path,
                    'added_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                $this->support->audit->record('compliance.audit_photo_added', null, ['audit_id' => $audit->id, 'item_id' => $item->id], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            if ($written) {
                $this->photos->forget($path);
            }
            throw $e;
        }
    }

    /** Removes a photo from an open audit: the file is deleted and the row stays, marked purged. */
    public function removePhoto(User $actor, string $photoId): void
    {
        $photoId = $this->uuid($photoId) ?? abort(404);
        $path = DB::transaction(function () use ($actor, $photoId): ?string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $photo = DB::table('compliance_audit_photos')->where('organisation_id', $actor->organisation_id)->where('public_id', $photoId)->lockForUpdate()->first();
            abort_if($photo === null, 404);
            $audit = DB::table('compliance_audits')->where('id', $photo->audit_id)->lockForUpdate()->first();
            $this->support->assertBranch($actor, (int) $audit->branch_id);
            if ($audit->status !== 'open') {
                throw ValidationException::withMessages(['compliance' => 'A submitted audit is final.']);
            }
            if ($photo->purged_at === null) {
                DB::table('compliance_audit_photos')->where('id', $photo->id)->update(['stored_path' => null, 'purged_at' => now()->utc()]);
                $this->support->audit->record('compliance.audit_photo_removed', null, ['audit_id' => $audit->id, 'photo_id' => $photo->id], $actor, null, $actor->organisation_id);
            }

            return $photo->stored_path;
        }, 3);
        $this->photos->forget($path);
    }

    /**
     * Submits the audit: every item answered, a photo where a failed item needs one, then final. One task per failed
     * item is written in the same transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(User $actor, string $auditId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $auditId = $this->uuid($auditId) ?? abort(404);

        return DB::transaction(function () use ($actor, $key, $auditId, $input): string {
            $actor = $this->support->lock($actor, CompliancePermissions::AUDITS_PERFORM);
            $hash = $this->support->hash([$actor->id, 'audit_submit', $auditId]);
            if (($replay = $this->support->replay($actor, $key, 'audit_submit', $hash)) !== null) {
                return $replay;
            }
            $audit = $this->lockedAudit($actor, $auditId);
            $this->support->assertLock($audit, $input);
            $items = DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->orderBy('position')->get();
            $answers = DB::table('compliance_audit_answers')->where('audit_id', $audit->id)->get()->keyBy('item_id');
            $unanswered = $items->filter(fn ($i): bool => ! $answers->has($i->id));
            if ($unanswered->isNotEmpty()) {
                throw ValidationException::withMessages(['compliance' => 'Answer every item before you submit ('.$unanswered->count().' left).']);
            }
            $failed = $items->filter(fn ($i): bool => $answers[$i->id]->result === 'fail');
            foreach ($failed as $item) {
                if ($item->photo_required && ! DB::table('compliance_audit_photos')->where('audit_id', $audit->id)->where('item_id', $item->id)->whereNull('purged_at')->exists()) {
                    throw ValidationException::withMessages(['compliance' => 'Add a photo for "'.$item->title.'" before you submit.']);
                }
            }
            DB::table('compliance_audits')->where('id', $audit->id)->update(['status' => 'submitted', 'submitted_by_user_id' => $actor->id, 'submitted_at' => now()->utc(),
                'lock_version' => $audit->lock_version + 1, 'updated_at' => now()->utc()]);
            foreach ($failed as $item) {
                DB::table('compliance_tasks')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $audit->branch_id, 'audit_id' => $audit->id,
                    'item_id' => $item->id, 'status' => 'open', 'verify_required' => (bool) $audit->verify_done_tasks, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'audit_submit', $hash, (string) $audit->public_id);
            $this->support->audit->record('compliance.audit_submitted', null, ['audit_id' => $audit->id, 'failed' => $failed->count(), 'items' => $items->count()], $actor, null, $actor->organisation_id);

            return (string) $audit->public_id;
        }, 3);
    }

    /** Streams one audit photo after the open is audited. Perform at the audit's branch, or the checklist manager. */
    public function openPhoto(User $actor, string $photoId): \stdClass
    {
        $photoId = $this->uuid($photoId) ?? abort(404);

        return DB::transaction(function () use ($actor, $photoId): \stdClass {
            $actor = $this->support->lockAny($actor, [CompliancePermissions::AUDITS_PERFORM, CompliancePermissions::CHECKLISTS_MANAGE]);
            $photo = DB::table('compliance_audit_photos')->where('organisation_id', $actor->organisation_id)->where('public_id', $photoId)->first();
            abort_if($photo === null || $photo->purged_at !== null, 404);
            if (! $actor->can(CompliancePermissions::CHECKLISTS_MANAGE)) {
                $this->support->assertBranch($actor, (int) DB::table('compliance_audits')->where('id', $photo->audit_id)->value('branch_id'));
            }
            $this->support->audit->record('compliance.audit_photo_opened', null, ['photo_id' => $photo->id, 'audit_id' => $photo->audit_id], $actor, null, $actor->organisation_id);

            return $photo;
        }, 3);
    }

    private function lockedAudit(User $actor, string $auditId): \stdClass
    {
        $audit = DB::table('compliance_audits')->where('organisation_id', $actor->organisation_id)->where('public_id', $auditId)->lockForUpdate()->first();
        abort_if($audit === null, 404);
        $this->support->assertBranch($actor, (int) $audit->branch_id);
        if ($audit->status !== 'open') {
            throw ValidationException::withMessages(['compliance' => 'This audit was submitted and is final.']);
        }

        return $audit;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
