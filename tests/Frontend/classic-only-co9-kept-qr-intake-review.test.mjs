// Classic only, CO-9: the Classic half of v2-qr-intake-review, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/RegistrationReview/Show.vue');
const loose = (s) => s.replace(/\s+/g, '');

test('Classic QR intake review sends its requests and keeps its form keys', () => {
    for (const piece of [
        '`/registration-review/${props.intake.publicId}/correction-required`',
        '`/registration-review/${props.intake.publicId}/reject`',
        '`/registration-review/${props.intake.publicId}/correct`',
        '`/registration-review/${props.intake.publicId}/accept`',
        '/guardian-contact?patient_number=${encodeURIComponent(patientNumber)}',
        'lock_version: props.intake.lockVersion',
        'idempotency_key: props.visitOptions.idempotencyKey',
        'guardian_choice',
        'guardian_lock_version',
        'duplicate_override: false',
        'assigned_doctor_user_id',
        'visit_reason_public_ids: [] as string[]',
        "priority: 'normal'",
        'coverage_verified: false',
        'confirm_repeat: false',
        'category: rejectionCategory.value',
        'correction.lock_version = lockVersion;',
        'acceptance.lock_version = lockVersion;',
        'acceptance.coverage_verified = false;',
        'if (guardianLookupBusy.value || guardianLookupError.value)',
    ]) {
        assert.ok(
            loose(classic).includes(loose(piece)),
            `Classic lacks ${piece}`,
        );
    }
});

test('Classic QR intake review shows every field of the correction form', () => {
    for (const key of [
        'full_name',
        'date_of_birth',
        'sex',
        'mobile_phone',
        'nationality_code',
        'identifier_type',
        'identifier_value',
        'guardian_name',
        'guardian_contact_number',
        'visit_purpose',
        'chief_complaint',
        'complaint_duration',
        'coverage_type',
        'panel_id',
        'coverage_member_reference',
    ]) {
        assert.ok(
            classic.includes(`correction.${key}`),
            `Classic lacks ${key}`,
        );
    }
});

test('Classic QR intake review keeps its duplicate default and wording', () => {
    assert.ok(classic.includes("resolution: 'match'"));
    assert.ok(
        classic.includes('No soft or exact duplicate candidate was found.'),
    );
});
