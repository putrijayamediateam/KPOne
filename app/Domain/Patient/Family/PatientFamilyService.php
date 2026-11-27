<?php

namespace App\Domain\Patient\Family;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FAM-1 (owner decisions 27 Nov 2026): staff link relatives by hand into one family; nothing is suggested from a shared
 * phone number or address. Reading needs the existing patient view policy on each member shown; changing needs the
 * existing patients.update.organisation permission and the patient update policy on every patient the change touches.
 *
 * Commands: add (link a relative into this patient's family, creating it or joining the relative's family), set_role and
 * remove. Each command carries the family version the screen saw (0 when the patient has no family), a UUID retry key and
 * the expected branch. Lock order: actor and grants, then the patients concerned in ascending id, then the family row,
 * then member rows, then visit rows (the same patient-before-visit order as registration).
 */
final class PatientFamilyService
{
    public const PERMISSION = 'patients.update.organisation';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
        private FamilyVisitGroups $groups,
    ) {}

    /** @return array<string, mixed> */
    public function directory(User $actor, Patient $patient): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        abort_unless($actor->is_active && $actor->organisation_id === $patient->organisation_id, 404);
        $membership = $this->activeMembership($patient);
        $canManage = $actor->can(self::PERMISSION) && Gate::forUser($actor)->allows('update', $patient) && ! $patient->isMergedAway();
        $members = [];
        $family = null;
        if ($membership) {
            $family = PatientFamily::query()->where('organisation_id', $patient->organisation_id)->findOrFail($membership->patient_family_id);
            foreach ($this->activeMembers($family) as $member) {
                $other = $member->patient;
                if (Gate::forUser($actor)->denies('view', $other)) {
                    continue;
                }
                $members[] = [
                    'patientNumber' => $other->patient_number,
                    'name' => $other->full_name,
                    'role' => $member->role,
                    'roleLabel' => FamilyRoles::LABELS[$member->role],
                    'isCurrent' => $other->id === $patient->id,
                    'merged' => $other->isMergedAway(),
                    'url' => route('patients.show', $other),
                    'canChange' => $canManage && Gate::forUser($actor)->allows('update', $other),
                ];
            }
        }

        return [
            'familyVersion' => $family === null ? 0 : $family->lock_version,
            'members' => $members,
            'canManage' => $canManage && $actor->can('patients.search.organisation'),
            'roles' => FamilyRoles::options(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, Patient $patient, array $input): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        Gate::forUser($actor)->authorize('update', $patient);
        $data = Validator::make($input, [
            'operation' => ['required', Rule::in(['add', 'set_role', 'remove'])],
            'relative_patient_number' => ['required', 'string', 'max:20'],
            'relative_role' => ['required_if:operation,set_role', 'nullable', Rule::in(array_keys(FamilyRoles::LABELS))],
            'patient_role' => ['nullable', Rule::in(array_keys(FamilyRoles::LABELS))],
            'expected_family_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $branch = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
        $relative = Patient::query()->where('organisation_id', $actor->organisation_id)
            ->where('patient_number', $data['relative_patient_number'])->first();
        if (! $relative) {
            $this->refuse('relative_patient_number', 'Select an existing patient.');
        }
        if ($data['operation'] === 'add' && $relative->id === $patient->id) {
            $this->refuse('relative_patient_number', 'Select a different patient.');
        }
        $canonical = [
            'organisation_id' => $actor->organisation_id,
            'patient_id' => $patient->id,
            'relative_id' => $relative->id,
            'operation' => $data['operation'],
            'relative_role' => $data['operation'] === 'remove' ? null : ($data['relative_role'] ?? null),
            'patient_role' => $data['operation'] === 'add' ? ($data['patient_role'] ?? null) : null,
            'expected_family_version' => (int) $data['expected_family_version'],
            'expected_branch_id' => $branch->id,
        ];
        $hash = hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key'));
        // Removing one of two members dissolves the family, which also changes the other member's membership. Every
        // membership change holds that patient's row lock, so that member's patient row is locked with the others, in
        // id order; the count is checked again under the family lock.
        $alsoLock = $data['operation'] === 'remove' ? $this->lastMemberIfDissolving($patient, $relative) : [];

        try {
            return $this->transact($actor, $branch, $patient, $relative, $data, $hash, $alsoLock);
        } catch (UniqueConstraintViolationException $exception) {
            // Two first saves with one retry key but different details and no shared patient row: the second meets the
            // retry key's unique index instead of the earlier-command check. Refuse it the same way.
            if (str_contains($exception->getMessage(), 'patient_family_commands_retry_unique')) {
                $this->refuse('family', 'This change was already saved with different details. Reload before saving.');
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $alsoLock
     * @return array<string, mixed>
     */
    private function transact(User $actor, Branch $branch, Patient $patient, Patient $relative, array $data, string $hash, array $alsoLock): array
    {
        return DB::transaction(function () use ($actor, $branch, $patient, $relative, $data, $hash, $alsoLock): array {
            $lockedActor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $ids = array_values(array_unique([$patient->id, $relative->id, ...$alsoLock]));
            sort($ids, SORT_NUMERIC);
            $locked = Patient::query()->where('organisation_id', $lockedActor->organisation_id)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($locked->count() === count($ids), 404);
            foreach ([$patient->id, $relative->id] as $id) {
                Gate::forUser($lockedActor)->authorize('view', $locked->get($id));
                Gate::forUser($lockedActor)->authorize('update', $locked->get($id));
            }
            $current = $locked->get($patient->id);
            $other = $locked->get($relative->id);

            $prior = DB::table('patient_family_commands')
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('actor_user_id', $lockedActor->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($prior) {
                if (! hash_equals($prior->payload_hash, $hash)) {
                    $this->refuse('family', 'This change was already saved with different details. Reload before saving.');
                }

                return $this->directory($lockedActor, $current);
            }

            if ($current->isMergedAway() || ($data['operation'] === 'add' && $other->isMergedAway())) {
                $this->refuse('family', 'A selected patient was merged into another record. Use the kept record.');
            }

            $family = match ($data['operation']) {
                'add' => $this->add($lockedActor, $branch, $current, $other, $data),
                'set_role' => $this->setRole($lockedActor, $current, $other, $data),
                'remove' => $this->remove($lockedActor, $current, $other, $data, array_values(array_map('intval', $locked->keys()->all()))),
                default => throw new \LogicException('Unknown family operation.'),
            };

            DB::table('patient_family_commands')->insert([
                'organisation_id' => $lockedActor->organisation_id,
                'actor_user_id' => $lockedActor->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'action' => $data['operation'],
                'patient_family_id' => $family->id,
                'result_version' => $family->lock_version,
                'created_at' => now()->utc(),
            ]);

            return $this->directory($lockedActor, $current);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function add(User $actor, Branch $branch, Patient $current, Patient $other, array $data): PatientFamily
    {
        $mine = $this->activeMembership($current);
        $theirs = $this->activeMembership($other);
        $myFamily = $this->lockAndCheckVersion($mine, $data);
        if ($mine && $theirs) {
            $this->refuse('relative_patient_number', $mine->patient_family_id === $theirs->patient_family_id
                ? 'This patient is already in this family.'
                : 'This patient is already in another family. Remove them from that family first.');
        }
        if (! $theirs && ($data['relative_role'] ?? null) === null) {
            $this->refuse('relative_role', 'Choose the relative\'s place in the family.');
        }
        if (! $mine && ! $theirs && ($data['patient_role'] ?? null) === null) {
            $this->refuse('patient_role', 'Choose this patient\'s place in the new family.');
        }
        if (! $mine && $theirs && ($data['patient_role'] ?? null) === null) {
            $this->refuse('patient_role', 'Choose this patient\'s place in the family they are joining.');
        }

        $family = match (true) {
            $myFamily !== null => $myFamily,
            $theirs !== null => $this->lockFamily($theirs->patient_family_id),
            default => $this->newFamily($actor),
        };
        $joining = [];
        if (! $mine) {
            $joining[] = [$current, $data['patient_role']];
        }
        if (! $theirs) {
            $joining[] = [$other, $data['relative_role']];
        }
        $now = now()->utc();
        $stamped = 0;
        foreach ($joining as [$patient, $role]) {
            $member = new PatientFamilyMember;
            $member->forceFill([
                'organisation_id' => $actor->organisation_id,
                'patient_family_id' => $family->id,
                'patient_id' => $patient->id,
                'role' => $role,
                'joined_at' => $now,
                'added_by_user_id' => $actor->id,
            ])->save();
            $stamped += $this->groups->stampOpenVisits($actor, $patient, $family, $branch);
        }
        $created = $family->wasRecentlyCreated;
        if (! $created) {
            $family->forceFill(['lock_version' => $family->lock_version + 1])->save();
        }
        $this->record($actor, $family, $created ? 'created' : 'member_added', array_map(fn (array $join): int => $join[0]->id, $joining), ['patient_id', 'role'], $stamped);

        return $family;
    }

    /** @param array<string, mixed> $data */
    private function setRole(User $actor, Patient $current, Patient $other, array $data): PatientFamily
    {
        $mine = $this->activeMembership($current);
        $myFamily = $this->lockAndCheckVersion($mine, $data);
        $target = $this->activeMembership($other);
        if (! $mine || ! $target || $target->patient_family_id !== $mine->patient_family_id) {
            $this->refuse('family', 'The family changed. Reload before saving.');
        }
        $family = $myFamily ?? $this->lockFamily($mine->patient_family_id);
        if ($target->role === $data['relative_role']) {
            $this->refuse('relative_role', 'Choose a different place in the family.');
        }
        $target->forceFill(['role' => $data['relative_role']])->save();
        $family->forceFill(['lock_version' => $family->lock_version + 1])->save();
        $this->record($actor, $family, 'role_changed', [$other->id], ['role'], 0);

        return $family;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $lockedPatientIds
     */
    private function remove(User $actor, Patient $current, Patient $other, array $data, array $lockedPatientIds): PatientFamily
    {
        $mine = $this->activeMembership($current);
        $myFamily = $this->lockAndCheckVersion($mine, $data);
        $target = $current->id === $other->id ? $mine : $this->activeMembership($other);
        if (! $mine || ! $target || $target->patient_family_id !== $mine->patient_family_id) {
            $this->refuse('family', 'The family changed. Reload before saving.');
        }
        $family = $myFamily ?? $this->lockFamily($mine->patient_family_id);
        $leaving = [$target];
        // A family of one is no family: when one member would be left, they leave too. The family row stays as evidence.
        $remaining = $this->activeMembers($family)->reject(fn (PatientFamilyMember $m): bool => $m->id === $target->id);
        if ($remaining->count() === 1) {
            /** @var PatientFamilyMember $last */
            $last = $remaining->first();
            if (! in_array($last->patient_id, $lockedPatientIds, true)) {
                $this->refuse('family', 'The family changed. Reload before saving.');
            }
            Gate::forUser($actor)->authorize('view', $last->patient);
            Gate::forUser($actor)->authorize('update', $last->patient);
            $leaving[] = $last;
        }
        $now = now()->utc();
        foreach ($leaving as $member) {
            $member->forceFill(['left_at' => $now, 'removed_by_user_id' => $actor->id])->save();
        }
        $family->forceFill(['lock_version' => $family->lock_version + 1])->save();
        $this->record($actor, $family, count($leaving) > 1 ? 'dissolved' : 'member_removed', array_map(fn (PatientFamilyMember $m): int => $m->patient_id, $leaving), ['left_at'], 0);

        return $family;
    }

    /** @return list<int> the other member's patient id when removing would leave one member, read without locks */
    private function lastMemberIfDissolving(Patient $patient, Patient $relative): array
    {
        $membership = $this->activeMembership($patient);
        if (! $membership) {
            return [];
        }
        $ids = PatientFamilyMember::query()->where('organisation_id', $patient->organisation_id)
            ->where('patient_family_id', $membership->patient_family_id)->whereNull('left_at')->pluck('patient_id')
            ->map(fn ($id): int => (int) $id)->all();

        return count($ids) === 2 ? array_values(array_diff($ids, [$relative->id])) : [];
    }

    /** Read under the patient row lock when writing: every membership change holds that patient's lock first. */
    private function activeMembership(Patient $patient): ?PatientFamilyMember
    {
        return PatientFamilyMember::query()
            ->where('organisation_id', $patient->organisation_id)
            ->where('patient_id', $patient->id)
            ->whereNull('left_at')
            ->first();
    }

    /** @return Collection<int, PatientFamilyMember> */
    private function activeMembers(PatientFamily $family): Collection
    {
        return PatientFamilyMember::query()
            ->where('organisation_id', $family->organisation_id)
            ->where('patient_family_id', $family->id)
            ->whereNull('left_at')
            ->with('patient')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    private function lockFamily(int $familyId): PatientFamily
    {
        return PatientFamily::query()->whereKey($familyId)->lockForUpdate()->firstOrFail();
    }

    private function newFamily(User $actor): PatientFamily
    {
        $family = new PatientFamily;
        $family->forceFill([
            'organisation_id' => $actor->organisation_id,
            'lock_version' => 1,
            'created_by_user_id' => $actor->id,
        ])->save();

        return $family;
    }

    /** @param array<string, mixed> $data */
    private function lockAndCheckVersion(?PatientFamilyMember $mine, array $data): ?PatientFamily
    {
        $family = $mine ? $this->lockFamily($mine->patient_family_id) : null;
        if (($family === null ? 0 : $family->lock_version) !== (int) $data['expected_family_version']) {
            $this->refuse('family', 'The family changed. Reload before saving.');
        }

        return $family;
    }

    /**
     * Values-free audit: ids, action, version and changed field names only; never names, numbers, phones or roles' text.
     *
     * @param  list<int>  $patientIds
     * @param  list<string>  $fields
     */
    private function record(User $actor, PatientFamily $family, string $action, array $patientIds, array $fields, int $visitsGrouped): void
    {
        $logged = $this->audit->record('patient.family.changed', $family, [
            'action' => $action,
            'patient_ids' => $patientIds,
            'record_version' => $family->lock_version,
            'changed_fields' => $fields,
            'visits_grouped' => $visitsGrouped,
        ], $actor, organisationId: $actor->organisation_id);
        abort_if($logged === null, 503);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
