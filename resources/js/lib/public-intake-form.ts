/**
 * Client-side guidance for the public QR intake form. These checks mirror
 * PublicIntakePayloadValidator so a patient sees problems before submitting;
 * the server remains the only authority and re-validates every submission.
 */
export type IntakeFormValues = {
    submission_type: string;
    full_name: string;
    date_of_birth: string;
    mobile_phone: string;
    identifier_type: string;
    identifier_value: string;
    identifier_issuing_country_code: string;
    visit_kind?: string;
    visit_purpose: string;
    chief_complaint: string;
    complaint_duration: string;
    coverage_type: string;
    panel_id: string | number;
    coverage_member_reference: string;
    guardian_name: string;
    guardian_relationship: string;
    guardian_contact_number: string;
    guardian_attestation: boolean;
    consent_confirmed: boolean;
};

export type StepErrors = Record<string, string[]>;

const filled = (value: string | number | null | undefined): boolean =>
    String(value ?? '').trim() !== '';

const tooLong = (value: string, max: number): boolean =>
    value.trim().length > max;

/** Parses a strict Y-m-d date; anything else (including roll-overs) is null. */
export const parseDateOfBirth = (value: string): Date | null => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const [year, month, day] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return date.getUTCFullYear() === year &&
        date.getUTCMonth() === month - 1 &&
        date.getUTCDate() === day
        ? date
        : null;
};

/** Whole years between a date of birth and today's calendar date. */
export const ageOn = (dateOfBirth: Date, today: Date): number => {
    const age = today.getUTCFullYear() - dateOfBirth.getUTCFullYear();
    const birthdayPassed =
        today.getUTCMonth() > dateOfBirth.getUTCMonth() ||
        (today.getUTCMonth() === dateOfBirth.getUTCMonth() &&
            today.getUTCDate() >= dateOfBirth.getUTCDate());

    return birthdayPassed ? age : age - 1;
};

/** Today's calendar date in the patient's own timezone, as a UTC midnight. */
export const localToday = (now: Date): Date =>
    new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));

export const validateIntakeStep = (
    step: number,
    form: IntakeFormValues,
    options: { minorAge: number; today: Date },
): StepErrors => {
    const errors: StepErrors = {};
    const add = (field: string, message: string) => {
        errors[field] = [...(errors[field] ?? []), message];
    };

    if (step === 2) {
        if (!filled(form.full_name)) {
            add('full_name', 'Nama penuh diperlukan.');
        } else if (tooLong(form.full_name, 255)) {
            add('full_name', 'Nama penuh terlalu panjang.');
        }

        const dateOfBirth = parseDateOfBirth(form.date_of_birth);

        if (!filled(form.date_of_birth)) {
            add('date_of_birth', 'Tarikh lahir diperlukan.');
        } else if (!dateOfBirth) {
            add('date_of_birth', 'Tarikh lahir tidak sah.');
        } else if (dateOfBirth.getTime() > options.today.getTime()) {
            add('date_of_birth', 'Tarikh lahir tidak boleh pada masa depan.');
        } else if (
            form.submission_type !== 'guardian' &&
            ageOn(dateOfBirth, options.today) < options.minorAge
        ) {
            add(
                'date_of_birth',
                `Pesakit bawah ${options.minorAge} tahun mesti didaftarkan oleh penjaga. Kembali ke langkah 1 dan pilih "Saya penjaga".`,
            );
        }

        if (!filled(form.mobile_phone)) {
            add('mobile_phone', 'Nombor telefon diperlukan.');
        } else if (tooLong(form.mobile_phone, 64)) {
            add('mobile_phone', 'Nombor telefon terlalu panjang.');
        }

        if (!filled(form.identifier_value)) {
            add('identifier_value', 'Nombor pengenalan diperlukan.');
        } else if (tooLong(form.identifier_value, 100)) {
            add('identifier_value', 'Nombor pengenalan terlalu panjang.');
        }

        if (
            form.identifier_type === 'passport' &&
            filled(form.identifier_issuing_country_code) &&
            !/^[A-Za-z]{2}$/.test(form.identifier_issuing_country_code.trim())
        ) {
            add(
                'identifier_value',
                'Kod negara pengeluar passport mesti 2 huruf, contoh MY.',
            );
        }

        const medicineOnly = form.visit_kind === 'otc';

        if (!medicineOnly && !filled(form.visit_purpose)) {
            add('visit_purpose', 'Sila pilih tujuan lawatan.');
        }

        if (medicineOnly) {
            if (tooLong(form.chief_complaint, 500)) {
                add('chief_complaint', 'Maklumat ini terlalu panjang.');
            }
        } else if (!filled(form.chief_complaint)) {
            add('chief_complaint', 'Sila nyatakan masalah atau tujuan utama.');
        } else if (tooLong(form.chief_complaint, 500)) {
            add('chief_complaint', 'Maklumat ini terlalu panjang.');
        }

        if (tooLong(form.complaint_duration, 120)) {
            add('complaint_duration', 'Maklumat ini terlalu panjang.');
        }
    }

    if (step === 3 && form.submission_type === 'guardian') {
        if (!filled(form.guardian_name)) {
            add('guardian_name', 'Nama penjaga diperlukan.');
        } else if (tooLong(form.guardian_name, 255)) {
            add('guardian_name', 'Nama penjaga terlalu panjang.');
        }

        if (!filled(form.guardian_relationship)) {
            add('guardian_relationship', 'Hubungan penjaga diperlukan.');
        }

        if (!filled(form.guardian_contact_number)) {
            add(
                'guardian_contact_number',
                'Nombor telefon penjaga diperlukan.',
            );
        } else if (tooLong(form.guardian_contact_number, 64)) {
            add(
                'guardian_contact_number',
                'Nombor telefon penjaga terlalu panjang.',
            );
        }

        if (!form.guardian_attestation) {
            add(
                'guardian_attestation',
                'Penjaga mesti mengesahkan kebenaran untuk menghantar maklumat ini.',
            );
        }
    }

    if (step === 4) {
        if (!['self_pay', 'panel'].includes(form.coverage_type)) {
            add('coverage_type', 'Sila pilih jenis bayaran.');
        } else if (form.coverage_type === 'panel' && !filled(form.panel_id)) {
            add('panel_id', 'Sila pilih panel.');
        }

        if (tooLong(form.coverage_member_reference, 100)) {
            add('coverage_member_reference', 'Nombor ahli terlalu panjang.');
        }
    }

    if (step === 5 && !form.consent_confirmed) {
        add(
            'consent_confirmed',
            'Persetujuan diperlukan sebelum maklumat dihantar.',
        );
    }

    return errors;
};

/** Seconds left before the intake session expires, never below zero. */
export const secondsRemaining = (expiresAt: string, now: Date): number => {
    const expiry = Date.parse(expiresAt);

    if (Number.isNaN(expiry)) {
        return 0;
    }

    return Math.max(0, Math.floor((expiry - now.getTime()) / 1000));
};

export const formatCountdown = (seconds: number): string => {
    const safe = Math.max(0, Math.floor(seconds));

    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
};

export const SESSION_WARNING_SECONDS = 180;

export type ReviewRow = { label: string; value: string; step: number };

const SEX_LABELS: Record<string, string> = {
    female: 'Perempuan',
    male: 'Lelaki',
    indeterminate: 'Tidak ditentukan',
    unknown: 'Tidak pasti',
};

const PURPOSE_LABELS: Record<string, string> = {
    doctor_illness: 'Jumpa doktor / sakit',
    pregnancy_check: 'Pemeriksaan kehamilan',
    scan: 'Scan',
    vaccination: 'Vaksin',
    medical_checkup: 'Medical check-up',
    procedure: 'Prosedur',
    other: 'Lain-lain',
};

const RELATIONSHIP_LABELS: Record<string, string> = {
    parent: 'Ibu / Bapa',
    legal_guardian: 'Penjaga sah',
    spouse: 'Suami / Isteri',
    adult_child: 'Anak dewasa',
    sibling: 'Adik-beradik',
    other: 'Lain-lain',
};

/** Read-only summary shown before submission; each row links back to its step. */
export const reviewRows = (
    form: IntakeFormValues & { sex: string },
    panelOptions: Array<{ id: number; name: string }>,
): ReviewRow[] => {
    const text = (value: string) => value.trim() || '—';
    const rows: ReviewRow[] = [
        {
            label: 'Diisi oleh',
            value: form.submission_type === 'guardian' ? 'Penjaga' : 'Pesakit',
            step: 1,
        },
        { label: 'Nama penuh', value: text(form.full_name), step: 2 },
        { label: 'Tarikh lahir', value: text(form.date_of_birth), step: 2 },
        { label: 'Jantina', value: SEX_LABELS[form.sex] ?? '—', step: 2 },
        { label: 'Nombor telefon', value: text(form.mobile_phone), step: 2 },
        {
            label: form.identifier_type === 'passport' ? 'Passport' : 'No. IC',
            value: text(form.identifier_value),
            step: 2,
        },
        form.visit_kind === 'otc'
            ? {
                  label: 'Tujuan lawatan',
                  value: 'Beli ubat sahaja',
                  step: 2,
              }
            : {
                  label: 'Tujuan lawatan',
                  value: PURPOSE_LABELS[form.visit_purpose] ?? '—',
                  step: 2,
              },
        {
            label:
                form.visit_kind === 'otc' ? 'Ubat diperlukan' : 'Masalah utama',
            value: text(form.chief_complaint),
            step: 2,
        },
    ];

    if (form.visit_kind !== 'otc' && filled(form.complaint_duration)) {
        rows.push({
            label: 'Sejak bila',
            value: text(form.complaint_duration),
            step: 2,
        });
    }

    if (form.submission_type === 'guardian') {
        rows.push(
            { label: 'Nama penjaga', value: text(form.guardian_name), step: 3 },
            {
                label: 'Hubungan',
                value: RELATIONSHIP_LABELS[form.guardian_relationship] ?? '—',
                step: 3,
            },
            {
                label: 'Telefon penjaga',
                value: text(form.guardian_contact_number),
                step: 3,
            },
        );
    }

    if (form.coverage_type === 'panel') {
        const panel = panelOptions.find(
            (option) => String(option.id) === String(form.panel_id),
        );
        rows.push({
            label: 'Jenis bayaran',
            value: `Panel — ${panel?.name ?? '—'}`,
            step: 4,
        });

        if (filled(form.coverage_member_reference)) {
            rows.push({
                label: 'Nombor ahli panel',
                value: text(form.coverage_member_reference),
                step: 4,
            });
        }
    } else {
        rows.push({
            label: 'Jenis bayaran',
            value: form.coverage_type === 'self_pay' ? 'Bayar sendiri' : '—',
            step: 4,
        });
    }

    return rows;
};
