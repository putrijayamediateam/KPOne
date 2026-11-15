<?php

namespace App\Domain\Patient\Services;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** REG-3 read side for the merge screens. Identifiers and phones are masked as on the search screen. */
final class PatientMergeDirectoryService
{
    public function __construct(
        private PatientIdentityService $identity,
        private PatientMergeService $merges,
    ) {}

    /** @return array<string, mixed> */
    public function panel(User $actor, Patient $patient): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        $requests = PatientMergeRequest::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('status', PatientMergeRequest::STATUS_PROPOSED)
            ->where(fn ($query) => $query->where('kept_patient_id', $patient->id)->orWhere('merged_patient_id', $patient->id))
            ->with(['keptPatient:id,patient_number', 'mergedPatient:id,patient_number', 'proposedBy:id,name'])
            ->orderBy('id')->get();

        return [
            'canPropose' => $actor->can(PatientMergeService::PROPOSE),
            'canApprove' => $actor->can(PatientMergeService::APPROVE),
            'lockVersion' => $patient->lock_version,
            'mergedFrom' => Patient::query()->where('merged_into_patient_id', $patient->id)->orderBy('merged_at')
                ->get(['id', 'patient_number', 'full_name', 'merged_at', 'lock_version'])
                ->map(fn (Patient $merged): array => [
                    'patientNumber' => $merged->patient_number,
                    'fullName' => $merged->full_name,
                    'mergedAt' => $merged->merged_at?->toIso8601String(),
                    'lockVersion' => $merged->lock_version,
                ])->values()->all(),
            'pending' => $requests->map(fn (PatientMergeRequest $request): array => $this->request($actor, $request))->values()->all(),
            'mergeReasons' => $this->options(PatientMergeRequest::MERGE_REASONS),
            'unmergeReasons' => $this->options(PatientMergeRequest::UNMERGE_REASONS),
        ];
    }

    /** @return array<string, mixed> */
    public function compare(User $actor, Patient $kept, string $otherNumber): array
    {
        Gate::forUser($actor)->authorize('view', $kept);
        $other = Patient::query()->where('organisation_id', $actor->organisation_id)->where('patient_number', strtoupper(trim($otherNumber)))->first();
        if (! $other) {
            throw ValidationException::withMessages(['other_patient_number' => 'Patient not found.']);
        }

        return [
            'kept' => $this->summary($kept),
            'other' => $this->summary($other),
            'blockers' => $this->merges->mergeBlockers($kept, $other),
        ];
    }

    /** @return array<string, mixed> */
    public function inbox(User $actor): array
    {
        $base = PatientMergeRequest::query()->where('organisation_id', $actor->organisation_id)
            ->with(['keptPatient:id,patient_number,full_name', 'mergedPatient:id,patient_number,full_name', 'proposedBy:id,name', 'decidedBy:id,name']);

        return [
            'open' => (clone $base)->where('status', PatientMergeRequest::STATUS_PROPOSED)->orderBy('id')->limit(100)->get()
                ->map(fn (PatientMergeRequest $request): array => $this->request($actor, $request))->values()->all(),
            'recent' => (clone $base)->where('status', '<>', PatientMergeRequest::STATUS_PROPOSED)->orderByDesc('decided_at')->limit(25)->get()
                ->map(fn (PatientMergeRequest $request): array => $this->request($actor, $request))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function request(User $actor, PatientMergeRequest $request): array
    {
        $reasons = $request->kind === PatientMergeRequest::KIND_MERGE ? PatientMergeRequest::MERGE_REASONS : PatientMergeRequest::UNMERGE_REASONS;

        return [
            'publicId' => $request->public_id,
            'kind' => $request->kind,
            'status' => $request->status,
            'keptPatientNumber' => $request->keptPatient?->patient_number,
            'keptName' => $request->keptPatient?->full_name,
            'mergedPatientNumber' => $request->mergedPatient?->patient_number,
            'mergedName' => $request->mergedPatient?->full_name,
            'reason' => $reasons[$request->reason_code] ?? $request->reason_code,
            'proposedBy' => $request->proposedBy?->name,
            'proposedAt' => $request->proposed_at->toIso8601String(),
            'decidedBy' => $request->decidedBy?->name,
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'lockVersion' => $request->lock_version,
            'canDecide' => $request->status === PatientMergeRequest::STATUS_PROPOSED && $actor->can(PatientMergeService::APPROVE) && $request->proposed_by_user_id !== $actor->id,
            'canWithdraw' => $request->status === PatientMergeRequest::STATUS_PROPOSED && $request->proposed_by_user_id === $actor->id,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Patient $patient): array
    {
        $identifier = $patient->identifiers()->whereNull('retired_at')->oldest('id')->first();
        $visits = Visit::query()->where('patient_id', $patient->id);

        return [
            'patientNumber' => $patient->patient_number,
            'fullName' => $patient->full_name,
            'dateOfBirth' => $patient->date_of_birth?->format('Y-m-d'),
            'sex' => $patient->sex,
            'identifier' => $identifier ? [
                'type' => $identifier->identifier_type,
                'maskedValue' => $this->identity->maskIdentifier($identifier->identifier_type, $identifier->normalized_value),
            ] : null,
            'maskedPhone' => $this->identity->maskPhone($patient->mobile_phone),
            'visitCount' => (clone $visits)->count(),
            'lastVisitAt' => (clone $visits)->max('registered_at'),
            'lockVersion' => $patient->lock_version,
            'mergedAway' => $patient->isMergedAway(),
        ];
    }

    /** @param array<string, string> $reasons
     * @return list<array{value: string, label: string}>
     */
    private function options(array $reasons): array
    {
        $options = [];
        foreach ($reasons as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
