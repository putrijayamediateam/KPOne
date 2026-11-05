<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Models\RosterWeek;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-1 roster planner mutations (B.E.R.N.I.S.S for the owner, 2026-11-05). Director and HR Manager draft a branch week;
 * whoever sends it cannot approve it; the Director approves; a change after approval is a new draft version. Every
 * write: fresh permission check under lock, transaction, row locks (actor, week, version, then each rostered person
 * in id order), lock_version, idempotency key where a retry could duplicate, an append-only action row and a structural
 * audit row (ids and field names only). Nothing here writes accounts, roles, branch assignments, rooms or clinical data.
 */
final class RosterService
{
    public const RETURN_REASONS = ['needs_changes' => 'Needs changes', 'wrong_staff' => 'Wrong staff', 'wrong_times' => 'Wrong times', 'other' => 'Other'];

    public function __construct(private RosterAuthority $authority, private RosterRules $rules, private AuditRecorder $audit) {}

    /**
     * Start a draft for a branch week: blank, a copy of the previous week's approved shifts, or a change of this week's
     * approved version. Copied shifts that are no longer allowed are left out and returned.
     *
     * @param  array<string, mixed>  $input
     * @return array{version: RosterVersion, skipped: list<string>}
     */
    public function startDraft(User $actor, array $input): array
    {
        $mode = $input['mode'] ?? null;
        if (! in_array($mode, ['blank', 'copy', 'change'], true)) {
            throw ValidationException::withMessages(['roster' => 'Choose how to start the draft.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $input, $mode, $key): array {
            $actor = $this->authority->lock($actor, RosterPermissions::MANAGE);
            $branch = $this->authority->branch($actor, $input['branch_id'] ?? null);
            $weekStart = $this->rules->weekStart($branch, $input['week'] ?? null);
            $this->serializeKey($actor, $key);
            $hash = $this->hash([$actor->id, 'start', $branch->id, $weekStart->toDateString(), $mode]);
            if (($replay = $this->replay($actor, $key, 'start', $hash)) !== null) {
                return ['version' => $replay, 'skipped' => []];
            }
            $week = $this->lockWeek($branch, $weekStart, create: true);
            $expected = $input['expected_week_lock_version'] ?? null;
            if ($expected !== null && filter_var($expected, FILTER_VALIDATE_INT) !== $week->lock_version) {
                throw $this->stale();
            }
            $versions = RosterVersion::query()->where('roster_week_id', $week->id)->lockForUpdate()->orderBy('id')->get();
            if ($versions->contains(fn (RosterVersion $v): bool => $v->isOpen())) {
                throw ValidationException::withMessages(['roster' => 'This week already has a draft or a week waiting for approval. Reload the page.']);
            }
            $approved = $versions->first(fn (RosterVersion $v): bool => $v->status === 'approved');
            $source = null;
            $offsetDays = 0;
            if ($mode === 'change') {
                $source = $approved ?? throw ValidationException::withMessages(['roster' => 'This week has no approved roster to change.']);
            } elseif ($mode === 'copy') {
                if ($approved !== null) {
                    throw ValidationException::withMessages(['roster' => 'This week is already approved. Use "Change this week" instead.']);
                }
                $previous = RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->subWeek()->toDateString())->first();
                $source = $previous === null ? null : RosterVersion::query()->where('roster_week_id', $previous->id)->where('status', 'approved')->first();
                if ($source === null) {
                    throw ValidationException::withMessages(['roster' => 'The previous week has no approved roster to copy.']);
                }
                $offsetDays = 7;
            } elseif ($approved !== null) {
                throw ValidationException::withMessages(['roster' => 'This week is already approved. Use "Change this week" instead.']);
            }

            $version = new RosterVersion;
            $version->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'roster_week_id' => $week->id, 'version_number' => (int) $versions->max('version_number') + 1, 'status' => 'draft',
                'based_on_version_id' => $source?->id, 'created_by_user_id' => $actor->id,
            ])->save();
            $skipped = $source === null ? [] : $this->copyShifts($actor, $branch, $source, $version, $offsetDays);
            $week->forceFill(['lock_version' => $week->lock_version + 1])->save();
            $this->action($week, $version, $mode === 'copy' ? 'week_copied' : 'draft_started', $actor);
            $this->remember($actor, $key, 'start', $hash, $version);
            $this->audit->record('hr.roster.draft_started', $version, ['roster_week_id' => $week->id, 'version_number' => $version->version_number,
                'mode' => $mode, 'based_on_version_id' => $source?->id, 'copied_shift_count' => $version->shifts()->count(), 'skipped_shift_count' => count($skipped)], $actor, $branch);

            return ['version' => $version, 'skipped' => $skipped];
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function addShift(User $actor, string $versionId, array $input): RosterVersion
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $versionId, $input, $key): RosterVersion {
            [$actor, $branch, $week, $version] = $this->lockDraft($actor, $versionId, $input);
            $this->serializeKey($actor, $key);
            $shift = $this->rules->parse($branch, $this->weekStartOf($week, $branch), $input);
            $hash = $this->hash([$actor->id, 'add', $version->id, $shift['staff_user_id'], $shift['starts_at']->toIso8601String(), $shift['ends_at']->toIso8601String(), $shift['break_minutes'], $shift['session_type'], $shift['branch_room_id']]);
            if (($replay = $this->replay($actor, $key, 'add_shift', $hash)) !== null) {
                return $replay;
            }
            $this->assertVersionLock($version, $input);
            $subject = $this->lockSubject($branch, $shift['staff_user_id']);
            $this->rules->assertShiftAllowed($version, $branch, $subject, $shift['starts_at'], $shift['ends_at']);

            $row = new RosterShift;
            $row->forceFill([...$shift, 'public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'roster_version_id' => $version->id, 'timezone' => $branch->timezone, 'created_by_user_id' => $actor->id])->save();
            $this->touch($version);
            $this->action($week, $version, 'shift_added', $actor, $row, $subject->id);
            $this->remember($actor, $key, 'add_shift', $hash, $version);
            $this->audit->record('hr.roster.shift_added', $version, ['shift_id' => $row->id, 'subject_user_id' => $subject->id, 'session_type' => $row->session_type], $actor, $branch);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function updateShift(User $actor, string $versionId, string $shiftId, array $input): RosterShift
    {
        return DB::transaction(function () use ($actor, $versionId, $shiftId, $input): RosterShift {
            [$actor, $branch, $week, $version] = $this->lockDraft($actor, $versionId, $input);
            $this->assertVersionLock($version, $input);
            $row = $this->lockShift($version, $shiftId);
            $shift = $this->rules->parse($branch, $this->weekStartOf($week, $branch), $input);
            $subject = $this->lockSubject($branch, $shift['staff_user_id']);
            $this->rules->assertShiftAllowed($version, $branch, $subject, $shift['starts_at'], $shift['ends_at'], $row->id);
            $before = ['staff_user_id' => $row->staff_user_id, 'starts_at' => $row->starts_at->toIso8601String(), 'ends_at' => $row->ends_at->toIso8601String(),
                'break_minutes' => $row->break_minutes, 'session_type' => $row->session_type, 'branch_room_id' => $row->branch_room_id];
            $after = ['staff_user_id' => $shift['staff_user_id'], 'starts_at' => $shift['starts_at']->toIso8601String(), 'ends_at' => $shift['ends_at']->toIso8601String(),
                'break_minutes' => $shift['break_minutes'], 'session_type' => $shift['session_type'], 'branch_room_id' => $shift['branch_room_id']];
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            if ($changed === []) {
                return $row;
            }
            $row->forceFill([...$shift, 'timezone' => $branch->timezone, 'lock_version' => $row->lock_version + 1])->save();
            $this->touch($version);
            $this->action($week, $version, 'shift_updated', $actor, $row, $subject->id);
            $this->audit->record('hr.roster.shift_updated', $version, ['shift_id' => $row->id, 'subject_user_id' => $subject->id, 'changed_fields' => $changed], $actor, $branch);

            return $row;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function removeShift(User $actor, string $versionId, string $shiftId, array $input): void
    {
        DB::transaction(function () use ($actor, $versionId, $shiftId, $input): void {
            [$actor, $branch, $week, $version] = $this->lockDraft($actor, $versionId, $input);
            $this->assertVersionLock($version, $input);
            $row = $this->lockShift($version, $shiftId);
            $subjectId = $row->staff_user_id;
            $this->action($week, $version, 'shift_removed', $actor, $row, $subjectId);
            $row->delete();
            $this->touch($version);
            $this->audit->record('hr.roster.shift_removed', $version, ['shift_id' => $row->id, 'subject_user_id' => $subjectId], $actor, $branch);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function submit(User $actor, string $versionId, array $input): RosterVersion
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $versionId, $input, $key): RosterVersion {
            $actor = $this->authority->lock($actor, RosterPermissions::MANAGE);
            [$branch, $week, $version] = $this->lockVersion($actor, $versionId);
            $this->serializeKey($actor, $key);
            $hash = $this->hash([$actor->id, 'submit', $version->id]);
            if (($replay = $this->replay($actor, $key, 'submit', $hash)) !== null) {
                return $replay;
            }
            if ($version->status !== 'draft') {
                throw ValidationException::withMessages(['roster' => 'Only a draft can be sent for approval. Reload the page.']);
            }
            $this->assertVersionLock($version, $input);
            if (! $version->shifts()->exists()) {
                throw ValidationException::withMessages(['roster' => 'Add at least one shift before sending the week for approval.']);
            }
            $version->forceFill(['status' => 'submitted', 'submitted_by_user_id' => $actor->id, 'submitted_at' => now()->utc(), 'return_reason' => null,
                'lock_version' => $version->lock_version + 1])->save();
            $this->action($week, $version, 'submitted', $actor);
            $this->remember($actor, $key, 'submit', $hash, $version);
            $this->audit->record('hr.roster.submitted', $version, ['roster_week_id' => $week->id, 'version_number' => $version->version_number, 'shift_count' => $version->shifts()->count()], $actor, $branch);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, string $versionId, array $input): RosterVersion
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $versionId, $input, $key): RosterVersion {
            $actor = $this->authority->lock($actor, RosterPermissions::APPROVE);
            [$branch, $week, $version] = $this->lockVersion($actor, $versionId);
            $this->serializeKey($actor, $key);
            $hash = $this->hash([$actor->id, 'approve', $version->id]);
            if (($replay = $this->replay($actor, $key, 'approve', $hash)) !== null) {
                return $replay;
            }
            if ($version->status !== 'submitted') {
                throw ValidationException::withMessages(['roster' => 'Only a week waiting for approval can be approved. Reload the page.']);
            }
            $this->assertVersionLock($version, $input);
            if ($version->submitted_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['roster' => 'You sent this week for approval. Someone else must approve it.']);
            }
            // Re-check every shift under lock: people, assignments and other approved weeks may have changed.
            $shifts = $version->shifts()->orderBy('staff_user_id')->orderBy('starts_at')->get();
            $subjects = [];
            foreach ($shifts->pluck('staff_user_id')->unique()->sort()->values() as $subjectId) {
                $subjects[(int) $subjectId] = $this->lockSubject($branch, (int) $subjectId, 'roster');
            }
            foreach ($shifts as $shift) {
                $this->rules->assertShiftAllowed($version, $branch, $subjects[$shift->staff_user_id], $shift->starts_at, $shift->ends_at, $shift->id, 'roster');
            }
            $previous = RosterVersion::query()->where('roster_week_id', $week->id)->where('status', 'approved')->lockForUpdate()->first();
            if ($previous !== null) {
                $previous->forceFill(['status' => 'superseded', 'superseded_at' => now()->utc(), 'lock_version' => $previous->lock_version + 1])->save();
                $this->action($week, $previous, 'superseded', $actor);
            }
            $version->forceFill(['status' => 'approved', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'lock_version' => $version->lock_version + 1])->save();
            $week->forceFill(['lock_version' => $week->lock_version + 1])->save();
            $this->action($week, $version, 'approved', $actor);
            $this->remember($actor, $key, 'approve', $hash, $version);
            $this->audit->record('hr.roster.approved', $version, ['roster_week_id' => $week->id, 'version_number' => $version->version_number,
                'shift_count' => $shifts->count(), 'superseded_version_id' => $previous?->id], $actor, $branch);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function returnToDraft(User $actor, string $versionId, array $input): RosterVersion
    {
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::RETURN_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the week is returned.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $versionId, $input, $reason, $key): RosterVersion {
            $actor = $this->authority->lock($actor, RosterPermissions::APPROVE);
            [$branch, $week, $version] = $this->lockVersion($actor, $versionId);
            $this->serializeKey($actor, $key);
            $hash = $this->hash([$actor->id, 'return', $version->id, $reason]);
            if (($replay = $this->replay($actor, $key, 'return', $hash)) !== null) {
                return $replay;
            }
            if ($version->status !== 'submitted') {
                throw ValidationException::withMessages(['roster' => 'Only a week waiting for approval can be returned. Reload the page.']);
            }
            $this->assertVersionLock($version, $input);
            if ($version->submitted_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['roster' => 'You sent this week for approval. Someone else must decide it.']);
            }
            $version->forceFill(['status' => 'draft', 'submitted_by_user_id' => null, 'submitted_at' => null, 'return_reason' => $reason,
                'lock_version' => $version->lock_version + 1])->save();
            $this->action($week, $version, 'returned', $actor, reason: $reason);
            $this->remember($actor, $key, 'return', $hash, $version);
            $this->audit->record('hr.roster.returned', $version, ['roster_week_id' => $week->id, 'version_number' => $version->version_number, 'reason' => $reason], $actor, $branch);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function discard(User $actor, string $versionId, array $input): RosterVersion
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $versionId, $input, $key): RosterVersion {
            $actor = $this->authority->lock($actor, RosterPermissions::MANAGE);
            [$branch, $week, $version] = $this->lockVersion($actor, $versionId);
            $this->serializeKey($actor, $key);
            $hash = $this->hash([$actor->id, 'discard', $version->id]);
            if (($replay = $this->replay($actor, $key, 'discard', $hash)) !== null) {
                return $replay;
            }
            if ($version->status !== 'draft') {
                throw ValidationException::withMessages(['roster' => 'Only a draft can be discarded. Reload the page.']);
            }
            $this->assertVersionLock($version, $input);
            $version->forceFill(['status' => 'discarded', 'lock_version' => $version->lock_version + 1])->save();
            $week->forceFill(['lock_version' => $week->lock_version + 1])->save();
            $this->action($week, $version, 'discarded', $actor);
            $this->remember($actor, $key, 'discard', $hash, $version);
            $this->audit->record('hr.roster.discarded', $version, ['roster_week_id' => $week->id, 'version_number' => $version->version_number], $actor, $branch);

            return $version;
        }, 3);
    }

    /** @return list<string> Human-readable notes for the shifts that could not be copied. */
    private function copyShifts(User $actor, Branch $branch, RosterVersion $source, RosterVersion $target, int $offsetDays): array
    {
        $skipped = [];
        $shifts = $source->shifts()->with('staff')->orderBy('staff_user_id')->orderBy('starts_at')->get();
        $locked = [];
        foreach ($shifts->pluck('staff_user_id')->unique()->sort()->values() as $subjectId) {
            $locked[(int) $subjectId] = User::query()->whereKey($subjectId)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->first();
        }
        foreach ($shifts as $shift) {
            // Shift by whole branch-local days, so the wall-clock time is kept.
            $startsAt = $shift->starts_at->setTimezone($branch->timezone)->addDays($offsetDays)->utc();
            $endsAt = $shift->ends_at->setTimezone($branch->timezone)->addDays($offsetDays)->utc();
            $subject = $locked[$shift->staff_user_id] ?? null;
            $day = $this->rules->shortDayLabel($startsAt->setTimezone($branch->timezone));
            try {
                if ($subject === null) {
                    throw ValidationException::withMessages(['roster' => 'not available']);
                }
                $this->rules->assertShiftAllowed($target, $branch, $subject, $startsAt, $endsAt);
                $room = $shift->branch_room_id !== null && DB::table('branch_rooms')->where('id', $shift->branch_room_id)->where('is_active', true)->exists() ? $shift->branch_room_id : null;
            } catch (ValidationException $e) {
                $reason = collect($e->errors())->flatten()->first();
                $skipped[] = ($subject?->name ?? 'A staff member')." on {$day}: ".(is_string($reason) && $reason !== 'not available' ? $reason : 'no longer available.');

                continue;
            }
            $row = new RosterShift;
            $row->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'roster_version_id' => $target->id, 'staff_user_id' => $shift->staff_user_id, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'timezone' => $branch->timezone, 'break_minutes' => $shift->break_minutes, 'session_type' => $shift->session_type,
                'branch_room_id' => $room, 'created_by_user_id' => $actor->id])->save();
        }

        return $skipped;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{User, Branch, RosterWeek, RosterVersion}
     */
    private function lockDraft(User $actor, string $versionId, array $input): array
    {
        $actor = $this->authority->lock($actor, RosterPermissions::MANAGE);
        [$branch, $week, $version] = $this->lockVersion($actor, $versionId);
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['roster' => 'Shifts can change only while the week is a draft. Reload the page.']);
        }

        return [$actor, $branch, $week, $version];
    }

    /** @return array{Branch, RosterWeek, RosterVersion} */
    private function lockVersion(User $actor, string $versionId): array
    {
        abort_unless(Str::isUuid($versionId), 404);
        $found = RosterVersion::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $versionId)->first();
        abort_if($found === null, 404);
        $week = RosterWeek::query()->whereKey($found->roster_week_id)->lockForUpdate()->firstOrFail();
        $version = RosterVersion::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
        $branch = Branch::query()->whereKey($week->branch_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();

        return [$branch, $week, $version];
    }

    private function lockWeek(Branch $branch, CarbonImmutable $weekStart, bool $create): RosterWeek
    {
        if ($create) {
            DB::table('hr_roster_weeks')->insertOrIgnore([['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'week_start' => $weekStart->toDateString(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]]);
        }

        return RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->lockForUpdate()->firstOrFail();
    }

    private function lockShift(RosterVersion $version, string $shiftId): RosterShift
    {
        abort_unless(Str::isUuid($shiftId), 404);
        $row = RosterShift::query()->where('roster_version_id', $version->id)->where('public_id', $shiftId)->lockForUpdate()->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** The rostered person's account row serialises every roster write for that person across branches. */
    private function lockSubject(Branch $branch, int $subjectId, string $field = 'staff_user_id'): User
    {
        $subject = User::query()->whereKey($subjectId)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->first();
        if ($subject === null) {
            throw ValidationException::withMessages([$field => 'Choose an active staff member.']);
        }

        return $subject;
    }

    private function weekStartOf(RosterWeek $week, Branch $branch): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $week->week_start->toDateString(), $branch->timezone) ?: throw new \LogicException('Invalid stored week.');
    }

    /** @param array<string, mixed> $input */
    private function assertVersionLock(RosterVersion $version, array $input): void
    {
        if (filter_var($input['expected_version_lock'] ?? null, FILTER_VALIDATE_INT) !== $version->lock_version) {
            throw $this->stale();
        }
    }

    private function stale(): ValidationException
    {
        return ValidationException::withMessages(['roster' => 'This week was changed by someone else. Reload to see the latest version.']);
    }

    private function touch(RosterVersion $version): void
    {
        $version->forceFill(['lock_version' => $version->lock_version + 1])->save();
    }

    private function action(RosterWeek $week, RosterVersion $version, string $action, User $actor, ?RosterShift $shift = null, ?int $subjectId = null, ?string $reason = null): void
    {
        DB::table('hr_roster_actions')->insert(['organisation_id' => $week->organisation_id, 'roster_week_id' => $week->id, 'roster_version_id' => $version->id,
            'action' => $action, 'actor_user_id' => $actor->id, 'shift_public_id' => $shift?->public_id, 'subject_user_id' => $subjectId,
            'reason' => $reason, 'occurred_at' => now()->utc()]);
    }

    private function serializeKey(User $actor, string $key): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_roster_key:'.$actor->organisation_id.':'.$key]);
    }

    private function replay(User $actor, string $key, string $action, string $hash): ?RosterVersion
    {
        $row = DB::table('hr_roster_requests')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash || (int) $row->actor_user_id !== $actor->id) {
            throw ValidationException::withMessages(['roster' => 'This request was already used for a different change. Reload and try again.']);
        }

        return RosterVersion::query()->findOrFail($row->roster_version_id);
    }

    private function remember(User $actor, string $key, string $action, string $hash, RosterVersion $version): void
    {
        DB::table('hr_roster_requests')->insert(['organisation_id' => $actor->organisation_id, 'idempotency_key' => $key, 'actor_user_id' => $actor->id,
            'action' => $action, 'payload_hash' => $hash, 'roster_version_id' => $version->id, 'created_at' => now()->utc()]);
    }

    /** @param list<mixed> $parts */
    private function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['roster' => 'Refresh this page and try again.']);
        }

        return $value;
    }
}
