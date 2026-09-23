<?php

namespace App\Domain\Patient\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicIntakePayloadValidator
{
    public const VISIT_PURPOSES = [
        'doctor_illness',
        'pregnancy_check',
        'scan',
        'vaccination',
        'medical_checkup',
        'procedure',
        'other',
    ];

    public function __construct(private PatientIdentityService $identity) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $lockedPrivacyNoticeVersion  The intake's own already-consented
     *                                                   version, passed only when validating a staff correction of an existing intake.
     *                                                   A correction must be checked and stamped against that original version, never
     *                                                   the version currently configured, or a version change would silently retract
     *                                                   or rewrite the patient's prior consent. Left null for a new public submission,
     *                                                   which must always consent to the currently configured version.
     * @return array<string, mixed>
     */
    public function validate(array $attributes, ?string $lockedPrivacyNoticeVersion = null): array
    {
        $privacyVersion = $lockedPrivacyNoticeVersion ?? (string) config('public-intake.privacy_notice_version');
        $validated = Validator::make($attributes, [
            'submission_type' => ['required', Rule::in(['patient', 'guardian'])],
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['female', 'male', 'indeterminate', 'unknown'])],
            'nationality_code' => ['nullable', 'string', 'size:2'],
            'mobile_phone' => ['required', 'string', 'max:64'],
            'phone_country' => ['required', 'string', 'size:2'],
            'identifier_type' => ['required', Rule::in(['nric', 'passport'])],
            'identifier_value' => ['required', 'string', 'max:100'],
            'identifier_issuing_country_code' => ['nullable', 'string', 'size:2'],
            'visit_purpose' => ['required', Rule::in(self::VISIT_PURPOSES)],
            'chief_complaint' => ['required', 'string', 'max:500'],
            'complaint_duration' => ['nullable', 'string', 'max:120'],
            'guardian_name' => ['nullable', 'required_if:submission_type,guardian', 'string', 'max:255'],
            'guardian_relationship' => [
                'nullable', 'required_if:submission_type,guardian',
                Rule::in(['parent', 'legal_guardian', 'spouse', 'adult_child', 'sibling', 'other']),
            ],
            'guardian_contact_number' => ['nullable', 'required_if:submission_type,guardian', 'string', 'max:64'],
            'guardian_attestation' => ['nullable', 'accepted_if:submission_type,guardian'],
            'consent_confirmed' => ['accepted'],
            'privacy_notice_version' => ['required', Rule::in([$privacyVersion])],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'queue_entry_id' => ['prohibited'],
        ], [
            'guardian_name.required_if' => 'Nama penjaga diperlukan.',
            'guardian_relationship.required_if' => 'Hubungan penjaga diperlukan.',
            'guardian_contact_number.required_if' => 'Nombor telefon penjaga diperlukan.',
            'guardian_attestation.accepted_if' => 'Penjaga mesti mengesahkan kebenaran untuk menghantar maklumat ini.',
            'consent_confirmed.accepted' => 'Persetujuan diperlukan sebelum maklumat dihantar.',
        ])->validate();

        $dob = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['date_of_birth']);
        if ($dob === null) {
            throw ValidationException::withMessages(['date_of_birth' => 'Tarikh lahir tidak sah.']);
        }
        if ($dob->age < (int) config('public-intake.minor_age', 18)
            && $validated['submission_type'] !== 'guardian') {
            throw ValidationException::withMessages([
                'submission_type' => 'Pendaftaran pesakit bawah umur mesti dihantar oleh penjaga.',
            ]);
        }

        $patient = $this->identity->normalizePatient([
            ...Arr::only($validated, [
                'full_name', 'date_of_birth', 'sex', 'nationality_code', 'mobile_phone', 'phone_country',
            ]),
            'email' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'postcode' => null,
            'city' => null,
            'state' => null,
            'country_code' => null,
        ]);
        $identifier = $this->identity->normalizeIdentifier([
            'identifier_type' => $validated['identifier_type'],
            'issuing_country_code' => $validated['identifier_issuing_country_code'] ?? null,
            'value' => $validated['identifier_value'],
        ]);

        $guardian = null;
        if ($validated['submission_type'] === 'guardian') {
            $guardian = [
                'name' => Str::squish((string) $validated['guardian_name']),
                'relationship' => $validated['guardian_relationship'],
                'contact_number' => (new PatientPhoneNormalizer)->normalize(
                    $validated['guardian_contact_number'],
                    $validated['phone_country'],
                    required: true,
                ),
                'attested' => true,
            ];
        }

        return [
            'submission_type' => $validated['submission_type'],
            'patient' => [
                ...Arr::only($patient, [
                    'full_name', 'date_of_birth', 'sex', 'nationality_code', 'mobile_phone', 'phone_country',
                    'email', 'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country_code',
                ]),
                'identifiers' => [[
                    'identifier_type' => $identifier['identifier_type'],
                    'issuing_country_code' => $identifier['issuing_country_code'],
                    'value' => $identifier['normalized_value'],
                ]],
            ],
            'guardian' => $guardian,
            'visit' => [
                'purpose' => $validated['visit_purpose'],
                'chief_complaint' => Str::squish((string) $validated['chief_complaint']),
                'duration' => filled($validated['complaint_duration'] ?? null)
                    ? Str::squish((string) $validated['complaint_duration']) : null,
            ],
            'consent' => [
                'confirmed' => true,
                'privacy_notice_version' => $privacyVersion,
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function editableFields(array $payload): array
    {
        $patient = Arr::wrap($payload['patient'] ?? []);
        $identifier = Arr::wrap($patient['identifiers'][0] ?? []);
        $guardian = Arr::wrap($payload['guardian'] ?? []);
        $visit = Arr::wrap($payload['visit'] ?? []);

        return [
            'submission_type' => $payload['submission_type'] ?? 'patient',
            'full_name' => $patient['full_name'] ?? '',
            'date_of_birth' => $patient['date_of_birth'] ?? '',
            'sex' => $patient['sex'] ?? 'unknown',
            'nationality_code' => $patient['nationality_code'] ?? null,
            'mobile_phone' => $patient['mobile_phone'] ?? '',
            'phone_country' => $patient['phone_country'] ?? 'MY',
            'identifier_type' => $identifier['identifier_type'] ?? 'nric',
            'identifier_value' => $identifier['value'] ?? '',
            'identifier_issuing_country_code' => $identifier['issuing_country_code'] ?? null,
            'guardian_name' => $guardian['name'] ?? null,
            'guardian_relationship' => $guardian['relationship'] ?? null,
            'guardian_contact_number' => $guardian['contact_number'] ?? null,
            'guardian_attestation' => $guardian['attested'] ?? false,
            'visit_purpose' => $visit['purpose'] ?? '',
            'chief_complaint' => $visit['chief_complaint'] ?? '',
            'complaint_duration' => $visit['duration'] ?? null,
            'consent_confirmed' => true,
            'privacy_notice_version' => $payload['consent']['privacy_notice_version'] ?? config('public-intake.privacy_notice_version'),
        ];
    }
}
