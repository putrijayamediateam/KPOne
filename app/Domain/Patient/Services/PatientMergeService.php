<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REG-3: two-person patient merge by link (owner decisions A-G, 2026-11-05). A merge never re-points invoices,
 * receipts, claims, visits or documents; the merged-away patient only gains a pointer to the kept patient.
 * Audit rows carry ids, kinds and reason codes only: never names, identifiers, phone, address or clinical text.
 */
final class PatientMergeService
{
    public const PROPOSE = 'patients.merge.propose.organisation';

    public const APPROVE = 'patients.merge.approve.organisation';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function propose(User $actor, Patient $kept, array $input): PatientMergeRequest
    {
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in([PatientMergeRequest::KIND_MERGE, PatientMergeRequest::KIND_UNMERGE])],
            'other_patient_number' => ['required', 'string', 'max:20'],
            'reason_code' => ['required', 'string'],
            'expected_branch_id' => ['required', 'integer'],
            'kept_lock_version' => ['required', 'integer', 'min:1'],
            'other_lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $reasons = $data['kind'] === PatientMergeRequest::KIND_MERGE ? PatientMergeRequest::MERGE_REASONS : PatientMergeRequest::UNMERGE_REASONS;
        if (! array_key_exists($data['reason_code'], $reasons)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a reason from the list.']);
        }

        return DB::transaction(function () use ($actor, $kept, $data): PatientMergeRequest {
            $branch = $this->authority->branch($actor, $data['expected_branch_id']);
            $user = $this->authority->lock($actor, $branch, self::PROPOSE);

            $other = Patient::query()->where('organisation_id', $user->organisation_id)
                ->where('patient_number', strtoupper(trim($data['other_patient_number'])))->first();
            if (! $other || $kept->organisation_id !== $user->organisation_id) {
                throw ValidationException::withMessages(['other_patient_number' => 'Patient not found.']);
            }
            [$keptLocked, $otherLocked] = $this->lockPair($kept->id, $other->id);
            $hash = hash('sha256', json_encode([$data['kind'], $keptLocked->id, $otherLocked->id, $data['reason_code']], JSON_THROW_ON_ERROR));

            $existing = PatientMergeRequest::query()->where('organisation_id', $user->organisation_id)
                ->where('proposed_by_user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different merge. Reload and try again.']);
                }

                return $existing;
            }
            if ($keptLocked->lock_version !== (int) $data['kept_lock_version'] || $otherLocked->lock_version !== (int) $data['other_lock_version']) {
                throw ValidationException::withMessages(['other_patient_number' => 'One of the patient records changed. Reload and review it again.']);
            }
            $this->assertEligible($data['kind'], $keptLocked, $otherLocked);
            if (PatientMergeRequest::query()->where('merged_patient_id', $otherLocked->id)->where('status', PatientMergeRequest::STATUS_PROPOSED)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['other_patient_number' => 'A merge request for this patient is already waiting for approval.']);
            }

            $request = new PatientMergeRequest;
            $request->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $user->organisation_id,
                'kind' => $data['kind'],
                'kept_patient_id' => $keptLocked->id,
                'merged_patient_id' => $otherLocked->id,
                'reason_code' => $data['reason_code'],
                'status' => PatientMergeRequest::STATUS_PROPOSED,
                'proposed_by_user_id' => $user->id,
                'proposed_at' => now(),
                'kept_lock_version' => $keptLocked->lock_version,
                'merged_lock_version' => $otherLocked->lock_version,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'lock_version' => 1,
            ])->save();
            $this->record('patient_merge.proposed', $request, $user, $branch);

            return $request;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, PatientMergeRequest $request, array $input): PatientMergeRequest
    {
        return $this->decide($actor, $request, $input, PatientMergeRequest::STATUS_APPROVED);
    }

    /** @param array<string, mixed> $input */
    public function reject(User $actor, PatientMergeRequest $request, array $input): PatientMergeRequest
    {
        return $this->decide($actor, $request, $input, PatientMergeRequest::STATUS_REJECTED);
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, PatientMergeRequest $request, array $input): PatientMergeRequest
    {
        return $this->decide($actor, $request, $input, PatientMergeRequest::STATUS_WITHDRAWN);
    }

    /**
     * Problems that would block a merge of $other into $kept, as plain English for the screen.
     *
     * @return list<string>
     */
    public function mergeBlockers(Patient $kept, Patient $other): array
    {
        $blockers = [];
        if ($kept->id === $other->id) {
            return ['A patient cannot be merged with itself.'];
        }
        if ($kept->organisation_id !== $other->organisation_id) {
            return ['Patient not found.'];
        }
        if ($kept->isMergedAway() || $other->isMergedAway()) {
            $blockers[] = 'One of these records is already merged into another record.';
        }
        if (Patient::query()->where('merged_into_patient_id', $other->id)->exists()) {
            $blockers[] = 'Other records are already merged into this duplicate. Merge into it instead, or unmerge them first.';
        }
        if (Visit::query()->whereIn('patient_id', [$kept->id, $other->id])->where('status', Visit::STATUS_REGISTERED)->exists()) {
            $blockers[] = 'One of these records has a visit that is not finished. Complete or cancel it first.';
        }
        if ($this->hasOpenQrIntake($kept, $other)) {
            $blockers[] = 'One of these records has a QR check-in waiting for review. Finish the review first.';
        }
        $missing = $this->allergiesMissingFromKept($kept, $other);
        if ($missing > 0) {
            $blockers[] = sprintf('The duplicate has %d active %s that the kept record does not show. A doctor must record %s on the kept record first.', $missing, $missing === 1 ? 'allergy' : 'allergies', $missing === 1 ? 'it' : 'them');
        }

        return $blockers;
    }

    /**
     * Decision B: an open QR intake has no patient_id until acceptance, so match it by the IC or passport it carries
     * (stored encrypted, so compared after decryption). Open intakes are few: they are purged after 30 days.
     */
    private function hasOpenQrIntake(Patient $kept, Patient $other): bool
    {
        $known = DB::table('patient_identifiers')->whereIn('patient_id', [$kept->id, $other->id])->whereNull('retired_at')
            ->get(['identifier_type', 'issuing_country_code', 'normalized_value'])
            ->map(fn (object $row): string => $row->identifier_type.'|'.($row->issuing_country_code ?? '').'|'.$row->normalized_value)
            ->all();
        if ($known === []) {
            return false;
        }
        foreach (PublicPatientIntake::query()->where('organisation_id', $kept->organisation_id)->whereNull('patient_id')
            ->whereNotNull('encrypted_payload')->whereIn('status', [
                PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_UNDER_REVIEW, PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
            ])->lazyById(100) as $intake) {
            foreach ((array) data_get($intake->encrypted_payload, 'patient.identifiers', []) as $identifier) {
                $key = ($identifier['identifier_type'] ?? '').'|'.($identifier['issuing_country_code'] ?? '').'|'.($identifier['value'] ?? '');
                if (in_array($key, $known, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function allergiesMissingFromKept(Patient $kept, Patient $other): int
    {
        $active = fn (Patient $patient): array => DB::table('patient_allergy_records')
            ->join('patient_allergy_profiles', 'patient_allergy_profiles.id', '=', 'patient_allergy_records.patient_allergy_profile_id')
            ->where('patient_allergy_profiles.patient_id', $patient->id)
            ->where('patient_allergy_records.status', 'active')
            ->pluck('patient_allergy_records.allergen_text')
            ->map(fn (string $text): string => $this->normaliseAllergen($text))
            ->filter()->unique()->values()->all();
        $keptTexts = $active($kept);

        return count(array_diff($active($other), $keptTexts));
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, PatientMergeRequest $request, array $input, string $status): PatientMergeRequest
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $request, $data, $status): PatientMergeRequest {
            $branch = $this->authority->branch($actor, $data['expected_branch_id']);
            $user = $this->authority->lock($actor, $branch, $status === PatientMergeRequest::STATUS_WITHDRAWN ? self::PROPOSE : self::APPROVE);
            if ($request->organisation_id !== $user->organisation_id) {
                abort(404);
            }
            [$kept, $merged] = $this->lockPair($request->kept_patient_id, $request->merged_patient_id);
            $locked = PatientMergeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $status && $locked->decided_by_user_id === $user->id && $locked->lock_version === (int) $data['lock_version'] + 1) {
                return $locked; // a repeated click on the same decision by the same person already succeeded
            }
            if ($locked->status !== PatientMergeRequest::STATUS_PROPOSED || $locked->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['request' => 'This request has changed. Reload and review it again.']);
            }
            if ($status === PatientMergeRequest::STATUS_WITHDRAWN) {
                if ($locked->proposed_by_user_id !== $user->id) {
                    throw ValidationException::withMessages(['request' => 'Only the person who proposed this request can withdraw it.']);
                }
            } elseif ($locked->proposed_by_user_id === $user->id) {
                throw ValidationException::withMessages(['request' => 'A different person must approve or reject this request.']);
            }
            if ($status === PatientMergeRequest::STATUS_APPROVED) {
                if ($kept->lock_version !== $locked->kept_lock_version || $merged->lock_version !== $locked->merged_lock_version) {
                    throw ValidationException::withMessages(['request' => 'One of the patient records changed after this request was made. Reject it and propose again.']);
                }
                $this->assertEligible($locked->kind, $kept, $merged);
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->record('patient_merge.'.$status, $locked, $user, $branch);

            if ($status === PatientMergeRequest::STATUS_APPROVED) {
                $this->apply($locked, $merged, $user, $branch);
            }

            return $locked;
        }, 3);
    }

    private function apply(PatientMergeRequest $request, Patient $merged, User $user, Branch $branch): void
    {
        $merge = $request->kind === PatientMergeRequest::KIND_MERGE;
        DB::table('patient_merges')->insert([
            'organisation_id' => $request->organisation_id,
            'patient_merge_request_id' => $request->id,
            'event' => $merge ? 'merged' : 'unmerged',
            'kept_patient_id' => $request->kept_patient_id,
            'merged_patient_id' => $request->merged_patient_id,
            'proposed_by_user_id' => $request->proposed_by_user_id,
            'approved_by_user_id' => $user->id,
            'occurred_at' => now(),
        ]);
        $merged->forceFill([
            'merged_into_patient_id' => $merge ? $request->kept_patient_id : null,
            'merged_at' => $merge ? now() : null,
            'lock_version' => $merged->lock_version + 1,
        ])->save();
        $this->audit->record($merge ? 'patient.merged' : 'patient.unmerged', $merged, [
            'patient_merge_request_public_id' => $request->public_id,
            'kept_patient_id' => $request->kept_patient_id,
            'merged_patient_id' => $request->merged_patient_id,
        ], $user, $branch);
    }

    private function assertEligible(string $kind, Patient $kept, Patient $other): void
    {
        if ($kind === PatientMergeRequest::KIND_UNMERGE) {
            if ($other->merged_into_patient_id !== $kept->id) {
                throw ValidationException::withMessages(['other_patient_number' => 'This record is not merged into this patient.']);
            }

            return;
        }
        $blockers = $this->mergeBlockers($kept, $other);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['other_patient_number' => $blockers]);
        }
    }

    /** Lock both patients in id order so concurrent requests cannot deadlock.
     * @return array{0: Patient, 1: Patient}
     */
    private function lockPair(int $keptId, int $otherId): array
    {
        if ($keptId === $otherId) {
            throw ValidationException::withMessages(['other_patient_number' => 'A patient cannot be merged with itself.']);
        }
        $rows = Patient::query()->whereKey([$keptId, $otherId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $kept = $rows->get($keptId);
        $other = $rows->get($otherId);
        if (! $kept instanceof Patient || ! $other instanceof Patient) {
            throw ValidationException::withMessages(['other_patient_number' => 'Patient not found.']);
        }
        // Allergy rows are read for the safety check; lock their profiles too.
        PatientAllergyProfile::query()->whereIn('patient_id', [$keptId, $otherId])->orderBy('id')->lockForUpdate()->get();

        return [$kept, $other];
    }

    private function normaliseAllergen(string $text): string
    {
        $text = Str::lower(trim($text));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    private function record(string $event, PatientMergeRequest $request, User $user, Branch $branch): void
    {
        $this->audit->record($event, $request, [
            'patient_merge_request_public_id' => $request->public_id,
            'kind' => $request->kind,
            'reason_code' => $request->reason_code,
            'kept_patient_id' => $request->kept_patient_id,
            'merged_patient_id' => $request->merged_patient_id,
        ], $user, $branch);
    }
}
