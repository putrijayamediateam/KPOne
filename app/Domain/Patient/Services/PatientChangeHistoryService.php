<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * REG-4 (owner decisions 2026-11-05): a read-only list of who changed which parts of a patient record and when,
 * built from existing audit rows. Field names only: no old or new value, name, identifier, phone or address ever
 * leaves this service. CA Supervisor and Director only, through patients.history.view.organisation.
 */
final class PatientChangeHistoryService
{
    public const PERMISSION = 'patients.history.view.organisation';

    public const FIRST_PAGE = 10;

    public const PER_PAGE = 20;

    /** Changes only (owner decision: WhatsApp opens and other views are not listed). */
    public const EVENTS = [
        'patient.created' => 'Patient record created',
        'patient.demographics.updated' => 'Personal details changed',
        'patient.contact.updated' => 'Contact details changed',
        'patient.address.updated' => 'Address changed',
        'patient.identifier.added' => 'Identifier added',
        'patient.identifier.retired' => 'Identifier retired',
        'patient.guardian.saved' => 'Guardian contact saved',
        'patient.adult_confirmed' => 'Confirmed as adult (date of birth unknown)',
        'patient.merged' => 'Records merged',
        'patient.unmerged' => 'Merge undone',
    ];

    public const FIELDS = [
        'full_name' => 'Full name',
        'date_of_birth' => 'Date of birth',
        'sex' => 'Gender',
        'nationality_code' => 'Nationality',
        'mobile_phone' => 'Mobile phone',
        'email' => 'Email',
        'address_line_1' => 'Address line 1',
        'address_line_2' => 'Address line 2',
        'postcode' => 'Postcode',
        'city' => 'City',
        'state' => 'State',
        'country_code' => 'Country',
    ];

    private const IDENTIFIER_TYPES = ['nric' => 'NRIC', 'passport' => 'Passport'];

    public function __construct(
        private BranchAccessService $branches,
        private StaffAccessService $staff,
    ) {}

    /** @return array<string, mixed> */
    public function page(User $actor, Patient $patient, ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        if (! $actor->can(self::PERMISSION) || $actor->organisation_id !== $patient->organisation_id) {
            throw new AuthorizationException('You may not view this patient change history.');
        }

        $groupIds = PatientMergeLinks::groupIds($patient->id);
        // Records once merged into this one and later unmerged leave the group, but their merge and unmerge rows stay
        // part of this record's story. Looked up from the requests table so the audit query keeps using its
        // (subject_type, subject_id) index instead of reading JSON metadata.
        $formerIds = DB::table('patient_merge_requests')
            ->where('organisation_id', $patient->organisation_id)
            ->where('kept_patient_id', $patient->id)
            ->where('status', PatientMergeRequest::STATUS_APPROVED)
            ->distinct()->pluck('merged_patient_id')->map(fn ($id): int => (int) $id)
            ->diff($groupIds)->values()->all();
        $numbers = Patient::query()->whereIn('id', $groupIds)->pluck('patient_number', 'id');
        $numbers = $numbers->union(Patient::query()->whereIn('id', $formerIds)->pluck('patient_number', 'id'));
        $timezone = $this->branches->activeBranch($actor)?->timezone ?: (string) config('app.timezone', 'UTC');

        $paginator = AuditLog::query()
            ->where('organisation_id', $patient->organisation_id)
            ->whereIn('event', array_keys(self::EVENTS))
            ->where('subject_type', $patient->getMorphClass())
            ->where(fn (Builder $query) => $query
                ->whereIn('subject_id', $groupIds)
                ->when($formerIds !== [], fn (Builder $former) => $former->orWhere(fn (Builder $merge) => $merge
                    ->whereIn('subject_id', $formerIds)->whereIn('event', ['patient.merged', 'patient.unmerged']))))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->cursorPaginate(max(1, min($perPage, self::PER_PAGE)), ['id', 'event', 'subject_id', 'actor_user_id', 'metadata', 'occurred_at'], 'cursor', $this->cursor($cursor));

        $otherIds = collect($paginator->items())->flatMap(fn (AuditLog $row): array => [
            $row->metadata['kept_patient_id'] ?? null, $row->metadata['merged_patient_id'] ?? null, $row->subject_id,
        ])->filter()->map(fn ($id): int => (int) $id)->diff($numbers->keys())->unique()->values();
        if ($otherIds->isNotEmpty()) {
            $numbers = $numbers->union(Patient::query()->where('organisation_id', $patient->organisation_id)
                ->whereIn('id', $otherIds)->pluck('patient_number', 'id'));
        }

        // BIL-2 rule: a staff name shows only to viewers who may see that staff member; otherwise "Staff member".
        $names = $this->staff->visibleUsers($actor)
            ->whereIn('id', collect($paginator->items())->pluck('actor_user_id')->filter()->unique()->values())
            ->pluck('name', 'id')->all();

        return [
            'patientNumber' => $patient->patient_number,
            'timezone' => $timezone,
            'rows' => collect($paginator->items())->map(fn (AuditLog $row): array => $this->row($row, $patient, $numbers->all(), $names, $timezone))->values()->all(),
            'nextCursor' => $paginator->nextCursor()?->encode(),
        ];
    }

    /** A cursor is only ever one this service issued: anything else is refused, never passed to the database. */
    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $cursor = Cursor::fromEncoded($encoded);
        $values = $cursor?->toArray() ?? [];
        $id = $values['id'] ?? null;
        $occurred = $values['occurred_at'] ?? null;
        $valid = $cursor !== null && count($values) === 3 && is_bool($values['_pointsToNextItems'] ?? null)
            && (is_int($id) || (is_string($id) && ctype_digit($id)))
            && is_string($occurred) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $occurred) === 1
            && strtotime($occurred) !== false;
        if (! $valid) {
            throw ValidationException::withMessages(['cursor' => 'This list changed. Reload the page.']);
        }

        return $cursor;
    }

    /**
     * @param  array<int, string>  $numbers
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function row(AuditLog $row, Patient $patient, array $numbers, array $names, string $timezone): array
    {
        $metadata = $row->metadata ?? [];
        $fields = collect((array) ($metadata['changed_fields'] ?? []))
            ->map(fn ($field): ?string => is_string($field) ? (self::FIELDS[$field] ?? null) : null)
            ->filter()->unique()->values()->all();
        $label = self::EVENTS[$row->event];
        $kept = null;
        $merged = null;
        if (in_array($row->event, ['patient.identifier.added', 'patient.identifier.retired'], true)) {
            $type = self::IDENTIFIER_TYPES[$metadata['identifier_type'] ?? ''] ?? null;
            if ($type) {
                $label = $type.($row->event === 'patient.identifier.added' ? ' added' : ' retired');
            }
        } elseif ($row->event === 'patient.created') {
            $fields = collect((array) ($metadata['identifier_types'] ?? []))
                ->map(fn ($type): ?string => is_string($type) ? (self::IDENTIFIER_TYPES[$type] ?? null) : null)->filter()->unique()->values()->all();
        } elseif (in_array($row->event, ['patient.merged', 'patient.unmerged'], true)) {
            $kept = $numbers[(int) ($metadata['kept_patient_id'] ?? 0)] ?? null;
            $merged = $numbers[(int) ($metadata['merged_patient_id'] ?? $row->subject_id)] ?? null;
        }
        $occurred = $row->occurred_at;

        return [
            'id' => $row->id,
            'event' => $row->event,
            'label' => $label,
            'keptPatientNumber' => $kept,
            'mergedPatientNumber' => $merged,
            'fields' => $fields,
            'recordNumber' => $numbers[(int) $row->subject_id] ?? null,
            'isThisRecord' => (int) $row->subject_id === $patient->id,
            'actorName' => $row->actor_user_id === null ? 'System' : ($names[(int) $row->actor_user_id] ?? 'Staff member'),
            'occurredAt' => $occurred->toIso8601String(),
            'occurredAtLocal' => $occurred->setTimezone($timezone)->format('j M Y, g:i A'),
        ];
    }
}
