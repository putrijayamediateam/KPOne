<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientProblemService
{
    public function __construct(
        private CurrentClinicalCareService $care,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function add(User $actor, Visit $visit, array $attributes): PatientProblemRecord
    {
        $validated = $this->validateProblem($attributes, false);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): PatientProblemRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'problems.update.own');
            $this->lockProblems($care);
            $record = new PatientProblemRecord;
            $record->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $care->actor->organisation_id,
                'patient_id' => $care->patient->id,
                'condition_text' => $validated['condition_text'],
                'condition_code' => $validated['condition_code'],
                'code_system' => $validated['code_system'],
                'status' => PatientProblemRecord::STATUS_ACTIVE,
                'onset_date' => $validated['onset_date'],
                'resolved_date' => null,
                'recorded_by_user_id' => $care->actor->id,
                'updated_by_user_id' => $care->actor->id,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'entered_in_error_at' => null,
                'entered_in_error_by_user_id' => null,
                'lock_version' => 1,
            ])->save();

            $this->audit->record('problem.created', $record, [
                'record_version' => 1,
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Visit $visit, string $publicId, array $attributes): PatientProblemRecord
    {
        $validated = $this->validateProblem($attributes, true);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $publicId, $branch, $validated): PatientProblemRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'problems.update.own');
            $record = $this->problem($this->lockProblems($care), $publicId, true);
            $this->assertVersion($record, (int) $validated['lock_version']);
            $changes = [
                'condition_text' => $validated['condition_text'],
                'condition_code' => $validated['condition_code'],
                'code_system' => $validated['code_system'],
                'onset_date' => $validated['onset_date'],
            ];
            $before = [
                'condition_text' => $record->condition_text,
                'condition_code' => $record->condition_code,
                'code_system' => $record->code_system,
                'onset_date' => $record->onset_date?->toDateString(),
            ];
            if ($before === $changes) {
                return $record;
            }

            $record->forceFill([
                ...$changes,
                'updated_by_user_id' => $care->actor->id,
                'lock_version' => $record->lock_version + 1,
            ])->save();
            $this->audit->record('problem.updated', $record, [
                'record_version' => $record->lock_version,
                'changed_sections' => ['problem_details'],
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function resolve(User $actor, Visit $visit, string $publicId, array $attributes): PatientProblemRecord
    {
        $validated = $this->validateTransition($attributes, true);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $publicId, $branch, $validated): PatientProblemRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'problems.update.own');
            $record = $this->problem($this->lockProblems($care), $publicId, true);
            $this->assertVersion($record, (int) $validated['lock_version']);
            if ($validated['resolved_date'] !== null && $record->onset_date !== null
                && $validated['resolved_date'] < $record->onset_date->toDateString()) {
                throw ValidationException::withMessages([
                    'resolved_date' => 'The resolved date cannot be before the onset date.',
                ]);
            }

            $record->forceFill([
                'status' => PatientProblemRecord::STATUS_RESOLVED,
                'resolved_date' => $validated['resolved_date'],
                'resolved_at' => now()->utc(),
                'resolved_by_user_id' => $care->actor->id,
                'updated_by_user_id' => $care->actor->id,
                'lock_version' => $record->lock_version + 1,
            ])->save();
            $this->audit->record('problem.resolved', $record, [
                'record_version' => $record->lock_version,
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function enterInError(User $actor, Visit $visit, string $publicId, array $attributes): PatientProblemRecord
    {
        $validated = $this->validateTransition($attributes, false);
        $branch = $this->care->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $publicId, $branch, $validated): PatientProblemRecord {
            $care = $this->care->lock($actor, $visit, $branch, 'problems.update.own');
            $record = $this->problem($this->lockProblems($care), $publicId, false);
            $this->assertVersion($record, (int) $validated['lock_version']);

            $record->forceFill([
                'status' => PatientProblemRecord::STATUS_ENTERED_IN_ERROR,
                'entered_in_error_at' => now()->utc(),
                'entered_in_error_by_user_id' => $care->actor->id,
                'updated_by_user_id' => $care->actor->id,
                'lock_version' => $record->lock_version + 1,
            ])->save();
            $this->audit->record('problem.entered_in_error', $record, [
                'record_version' => $record->lock_version,
            ], $care->actor, $care->branch, $care->actor->organisation_id);

            return $record->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateProblem(array $attributes, bool $requiresVersion): array
    {
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => [$requiresVersion ? 'required' : 'prohibited', 'integer', 'min:1'],
            'condition_text' => ['required', 'string', 'max:500'],
            'condition_code' => ['nullable', 'string', 'max:50', 'required_with:code_system'],
            'code_system' => ['nullable', 'string', 'max:50', 'required_with:condition_code'],
            'onset_date' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $validated['condition_text'] = trim($validated['condition_text']);
        if ($validated['condition_text'] === '') {
            throw ValidationException::withMessages(['condition_text' => 'Enter the condition.']);
        }
        $validated['condition_code'] = $this->nullableTrim($validated['condition_code'] ?? null);
        $validated['code_system'] = $this->nullableTrim($validated['code_system'] ?? null);
        $validated['onset_date'] = $validated['onset_date'] ?? null;

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateTransition(array $attributes, bool $withResolvedDate): array
    {
        return Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'resolved_date' => [$withResolvedDate ? 'nullable' : 'prohibited', Rule::date()->format('Y-m-d')],
        ])->validate();
    }

    /** @return Collection<int, PatientProblemRecord> */
    private function lockProblems(CurrentClinicalCareContext $care): Collection
    {
        return PatientProblemRecord::query()
            ->where('organisation_id', $care->actor->organisation_id)
            ->where('patient_id', $care->patient->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, PatientProblemRecord> $records */
    private function problem(Collection $records, string $publicId, bool $activeOnly): PatientProblemRecord
    {
        $record = $records->first(fn (PatientProblemRecord $candidate): bool => $candidate->public_id === $publicId
            && (! $activeOnly || $candidate->status === PatientProblemRecord::STATUS_ACTIVE)
            && $candidate->status !== PatientProblemRecord::STATUS_ENTERED_IN_ERROR);
        abort_unless($record instanceof PatientProblemRecord, 404);

        return $record;
    }

    private function assertVersion(PatientProblemRecord $record, int $expected): void
    {
        if ($record->lock_version !== $expected) {
            throw ValidationException::withMessages([
                'lock_version' => 'This Problem Record changed after it was opened. Review the latest record.',
            ]);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
