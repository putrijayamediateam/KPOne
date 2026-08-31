<?php

namespace App\Domain\Patient\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PatientDirectoryService
{
    public function __construct(private PatientIdentityService $identity) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function search(User $actor, array $criteria): array
    {
        Gate::forUser($actor)->authorize('search', Patient::class);
        $query = trim((string) ($criteria['query'] ?? ''));
        $type = (string) ($criteria['search_type'] ?? 'name');
        $issuer = is_string($criteria['issuing_country_code'] ?? null) ? $criteria['issuing_country_code'] : null;
        $page = is_numeric($criteria['page'] ?? null) ? (int) $criteria['page'] : 1;

        $patients = Patient::query()
            ->select(['id', 'patient_number', 'full_name', 'date_of_birth', 'sex', 'mobile_phone'])
            ->where('organisation_id', $actor->organisation_id)
            ->with(['identifiers' => fn ($builder) => $builder
                ->select(['id', 'patient_id', 'identifier_type', 'normalized_value'])
                ->whereNull('retired_at')
                ->oldest('id')]);

        match ($type) {
            'patient_number' => $patients->where('patient_number', Str::upper($query)),
            'nric', 'passport' => $this->applyIdentifierSearch($patients, $type, $query, $issuer),
            'phone' => $patients->where('mobile_phone', $this->identity->normalizeSearchPhone($query)),
            default => $patients->whereRaw(
                "search_name LIKE ? ESCAPE '\\'",
                ['%'.$this->escapeLike(Str::lower($this->identity->normalizeSearchName($query))).'%'],
            ),
        };

        return $this->projectPaginator($patients->orderBy('full_name')->paginate(20, page: $page));
    }

    /**
     * Return a bounded, caller-ranked Patient summary list without exposing
     * internal identifiers or unmasked identity/contact values.
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public function summaries(User $actor, array $patientIds): array
    {
        Gate::forUser($actor)->authorize('search', Patient::class);
        $ids = collect($patientIds)
            ->unique()
            ->take(20)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $patients = Patient::query()
            ->select(['id', 'patient_number', 'full_name', 'date_of_birth', 'sex', 'mobile_phone'])
            ->where('organisation_id', $actor->organisation_id)
            ->whereKey($ids->all())
            ->with(['identifiers' => fn ($builder) => $builder
                ->select(['id', 'patient_id', 'identifier_type', 'normalized_value'])
                ->whereNull('retired_at')
                ->oldest('id')])
            ->get()
            ->keyBy('id');

        $summaries = [];
        foreach ($ids as $id) {
            $patient = $patients->get($id);
            if ($patient instanceof Patient) {
                $summaries[] = $this->projectPatient($patient);
            }
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array{patientNumber: string, fullName: string, dateOfBirth: string|null, maskedPhone: string|null}>
     */
    public function duplicateCandidates(User $actor, array $attributes): array
    {
        Gate::forUser($actor)->authorize('create', Patient::class);
        $normalized = $this->identity->normalizePatient($attributes);

        $results = [];
        foreach ($this->identity->possibleDuplicates($actor->organisation_id, $normalized) as $patient) {
            $results[] = [
                'patientNumber' => $patient->patient_number,
                'fullName' => $patient->full_name,
                'dateOfBirth' => $patient->date_of_birth?->format('Y-m-d'),
                'maskedPhone' => $this->identity->maskPhone($patient->mobile_phone),
            ];
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, Patient $patient): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        $patient->load(['identifiers' => fn ($query) => $query->latest('created_at')]);

        $activity = [];
        if ($actor->can('audit.view.organisation')) {
            $activity = AuditLog::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('subject_type', $patient->getMorphClass())
                ->where('subject_id', $patient->id)
                ->with('actor:id,name')
                ->latest('occurred_at')
                ->limit(25)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'event' => $log->event,
                    'actor' => $log->actor instanceof User ? $log->actor->name : 'System',
                    'occurredAt' => $log->occurred_at->toIso8601String(),
                    'changedFields' => $log->metadata['changed_fields'] ?? [],
                    'identifierType' => $log->metadata['identifier_type'] ?? null,
                ])->all();
        }

        return [
            'patientNumber' => $patient->patient_number,
            'identity' => [
                'fullName' => $patient->full_name,
                'dateOfBirth' => $patient->date_of_birth?->format('Y-m-d'),
                'sex' => $patient->sex,
                'nationalityCode' => $patient->nationality_code,
            ],
            'contact' => ['mobilePhone' => $patient->mobile_phone, 'email' => $patient->email],
            'address' => [
                'line1' => $patient->address_line_1,
                'line2' => $patient->address_line_2,
                'postcode' => $patient->postcode,
                'city' => $patient->city,
                'state' => $patient->state,
                'countryCode' => $patient->country_code,
            ],
            'identifiers' => $patient->identifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'type' => $identifier->identifier_type,
                'issuingCountryCode' => $identifier->issuing_country_code,
                'displayValue' => $this->identity->displayIdentifier($identifier->identifier_type, $identifier->normalized_value),
                'retiredAt' => $identifier->retired_at?->toIso8601String(),
                'isCurrent' => $identifier->retired_at === null,
            ])->values(),
            'administrative' => [
                'lockVersion' => $patient->lock_version,
                'createdAt' => $patient->created_at?->toIso8601String(),
                'updatedAt' => $patient->updated_at?->toIso8601String(),
            ],
            'activity' => $activity,
            'can' => [
                'update' => Gate::forUser($actor)->allows('update', $patient),
                'manageIdentifiers' => Gate::forUser($actor)->allows('manageIdentifiers', $patient),
            ],
        ];
    }

    /** @param Builder<Patient> $patients */
    private function applyIdentifierSearch(Builder $patients, string $type, string $query, ?string $issuer): void
    {
        $identifier = $this->identity->normalizeIdentifier([
            'identifier_type' => $type,
            'issuing_country_code' => $issuer,
            'value' => $query,
        ]);
        $patients->whereHas('identifiers', fn ($builder) => $builder
            ->where('identifier_type', $identifier['identifier_type'])
            ->where('issuing_country_code', $identifier['issuing_country_code'])
            ->where('normalized_value', $identifier['normalized_value']));
    }

    /**
     * @param  LengthAwarePaginator<int, Patient>  $paginator
     * @return array<string, mixed>
     */
    private function projectPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (Patient $patient): array => $this->projectPatient($patient))->values()->all(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @return array<string, mixed> */
    private function projectPatient(Patient $patient): array
    {
        $identifier = $patient->identifiers->first();

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
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
