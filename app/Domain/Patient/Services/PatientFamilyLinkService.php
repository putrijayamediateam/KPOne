<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientFamilyLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PatientFamilyLinkService
{
    public const LABELS = ['parent_of' => 'Parent of', 'child_of' => 'Child of', 'spouse' => 'Spouse of', 'sibling' => 'Sibling of', 'other_relative' => 'Other relative of'];

    public static function inverse(string $code): string
    {
        return match ($code) {
            'parent_of' => 'child_of', 'child_of' => 'parent_of', default => $code
        };
    }

    /** @return array<string,mixed> */
    public function directory(User $actor, Patient $patient): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        abort_unless($actor->is_active && $actor->organisation_id === $patient->organisation_id, 403);
        $links = PatientFamilyLink::query()->where('organisation_id', $actor->organisation_id)
            ->where(fn ($q) => $q->where('patient_low_id', $patient->id)->orWhere('patient_high_id', $patient->id))->orderBy('id')->get();
        $rows = [];
        foreach ($links as $link) {
            $other = Patient::query()->where('organisation_id', $actor->organisation_id)->find($link->patient_low_id === $patient->id ? $link->patient_high_id : $link->patient_low_id);
            if (! $other || Gate::forUser($actor)->denies('view', $other)) {
                continue;
            }
            $suppressed = $patient->isMergedAway() || $other->isMergedAway();
            $canUpdate = Gate::forUser($actor)->allows('update', $patient) && Gate::forUser($actor)->allows('update', $other);
            // Suppressed originals are visible only as a generic removal affordance to authorised updaters.
            if ($suppressed && (! $link->is_active || ! $canUpdate)) {
                continue;
            }
            $code = $link->patient_low_id === $patient->id ? $link->relationship_code : self::inverse($link->relationship_code);
            $rows[] = [
                'id' => $link->id, 'lockVersion' => $link->lock_version, 'active' => $link->is_active,
                'relationshipCode' => $code, 'relationshipLabel' => self::LABELS[$code], 'suppressed' => $suppressed,
                'patientNumber' => $suppressed ? null : $other->patient_number,
                'name' => $suppressed ? null : $other->full_name,
                'url' => $suppressed ? null : route('patients.show', $other), 'canUpdate' => $canUpdate,
            ];
        }

        return ['links' => $rows, 'canAdd' => ! $patient->isMergedAway() && Gate::forUser($actor)->allows('update', $patient) && $actor->can('patients.search.organisation')];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(User $actor, Patient $patient, array $input): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        Gate::forUser($actor)->authorize('update', $patient);
        $data = Validator::make($input, [
            'operation' => ['required', Rule::in(['link', 'correct', 'unlink'])],
            'other_patient_number' => ['required_without:link_id', 'nullable', 'string', 'max:80'],
            'link_id' => ['nullable', 'integer', 'min:1'],
            'relationship_code' => ['required', Rule::in(array_keys(self::LABELS))],
            'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        if (isset($data['link_id'])) {
            $known = PatientFamilyLink::query()->where('organisation_id', $actor->organisation_id)->whereKey($data['link_id'])->firstOrFail();
            abort_unless(in_array($patient->id, [$known->patient_low_id, $known->patient_high_id], true), 404);
            $otherId = $known->patient_low_id === $patient->id ? $known->patient_high_id : $known->patient_low_id;
            $other = Patient::query()->where('organisation_id', $actor->organisation_id)->findOrFail($otherId);
            if (isset($data['other_patient_number']) && $data['other_patient_number'] !== $other->patient_number) {
                $this->conflict('The selected patient does not match this family link.');
            }
        } else {
            $other = Patient::query()->where('organisation_id', $actor->organisation_id)->where('patient_number', $data['other_patient_number'])->firstOrFail();
        }
        if ($other->id === $patient->id) {
            $this->conflict('Select a different patient.');
        }
        $ids = [$patient->id, $other->id];
        sort($ids, SORT_NUMERIC);
        $code = $patient->id === $ids[0] ? $data['relationship_code'] : self::inverse($data['relationship_code']);
        $canonical = ['organisation_id' => $actor->organisation_id, 'low' => $ids[0], 'high' => $ids[1], 'operation' => $data['operation'], 'code' => $code, 'version' => (int) $data['lock_version']];
        $hash = hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $input['expected_branch_id'] ?? null);

        return DB::transaction(function () use ($authority, $actor, $branch, $ids, $patient, $data, $code, $hash): array {
            $lockedActor = $authority->lock($actor, $branch, 'patients.update.organisation');
            $endpoints = Patient::query()->where('organisation_id', $lockedActor->organisation_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($endpoints->count() === 2, 404);
            foreach ($endpoints as $endpoint) {
                Gate::forUser($lockedActor)->authorize('view', $endpoint);
                Gate::forUser($lockedActor)->authorize('update', $endpoint);
                if ($data['operation'] !== 'unlink' && $endpoint->isMergedAway()) {
                    $this->conflict('A selected patient was merged. Reload and select the current patient record.');
                }
            }
            $link = PatientFamilyLink::query()->where('organisation_id', $lockedActor->organisation_id)->where('patient_low_id', $ids[0])->where('patient_high_id', $ids[1])->lockForUpdate()->first();
            $command = DB::table('patient_family_link_commands')->where('organisation_id', $lockedActor->organisation_id)->where('actor_user_id', $lockedActor->id)->where('retry_key', $data['idempotency_key'])->first();
            if ($command) {
                if (! hash_equals($command->payload_hash, $hash)) {
                    $this->conflict('This retry key was already used for a different family change. Reload before saving.');
                }

                return $this->directory($lockedActor, $endpoints->firstWhere('id', $patient->id));
            }
            if ((int) $data['lock_version'] !== (int) ($link->lock_version ?? 0)
                || ($data['operation'] === 'link' && $link?->is_active)
                || ($data['operation'] !== 'link' && ! $link?->is_active)) {
                $this->conflict('The family link changed. Reload before saving.');
            }
            $link ??= new PatientFamilyLink(['organisation_id' => $lockedActor->organisation_id, 'patient_low_id' => $ids[0], 'patient_high_id' => $ids[1], 'lock_version' => 0]);
            // Unlink preserves the relation recorded on the original pair.
            if ($data['operation'] === 'unlink' && $code !== $link->relationship_code) {
                $this->conflict('The relationship changed. Reload before removing.');
            }
            $link->fill(['relationship_code' => $code, 'is_active' => $data['operation'] !== 'unlink', 'lock_version' => $link->lock_version + 1, 'updated_by_user_id' => $lockedActor->id])->save();
            abort_if(app(AuditRecorder::class)->record('patient.family_link.changed', $link, ['patient_low_id' => $ids[0], 'patient_high_id' => $ids[1], 'operation' => $data['operation'], 'record_version' => $link->lock_version, 'changed_fields' => ['relationship_code', 'is_active']], $lockedActor, organisationId: $lockedActor->organisation_id) === null, 503);
            DB::table('patient_family_link_commands')->insert(['organisation_id' => $lockedActor->organisation_id, 'link_id' => $link->id, 'actor_user_id' => $lockedActor->id, 'retry_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'action' => $data['operation'], 'result_relationship_code' => $code, 'result_is_active' => $link->is_active, 'result_version' => $link->lock_version, 'created_at' => now()->utc()]);

            return $this->directory($lockedActor, $endpoints->firstWhere('id', $patient->id));
        }, 3);
    }

    private function conflict(string $message): never
    {
        throw ValidationException::withMessages(['family_link' => $message]);
    }
}
