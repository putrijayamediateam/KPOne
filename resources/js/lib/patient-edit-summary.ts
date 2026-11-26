import type { PatientFormValues } from '@/types';

const fields = [
    ['full_name', 'Full name'],
    ['date_of_birth', 'Date of birth'],
    ['sex', 'Gender'],
    ['nationality_code', 'Nationality'],
    ['mobile_phone', 'Phone'],
    ['phone_country', 'Phone country'],
    ['email', 'Email'],
    ['address_line_1', 'Address line 1'],
    ['address_line_2', 'Address line 2'],
    ['postcode', 'Postcode'],
    ['city', 'City'],
    ['state', 'State'],
    ['country_code', 'Country'],
    ['adult_confirmed', 'Adult confirmation'],
] as const;

const guardianContent = (guardian: PatientFormValues['guardian']) =>
    guardian
        ? [
              guardian.relationship,
              guardian.full_name,
              guardian.phone,
              guardian.phone_country,
              guardian.email ?? '',
          ]
        : null;

export function patientEditChangedLabels(
    current: PatientFormValues,
    loaded: PatientFormValues,
): string[] {
    const labels: string[] = fields
        .filter(([key]) => current[key] !== loaded[key])
        .map(([, label]) => label);

    if (
        JSON.stringify(guardianContent(current.guardian)) !==
        JSON.stringify(guardianContent(loaded.guardian))
    ) {
        labels.push('Guardian contact');
    }

    return labels;
}
