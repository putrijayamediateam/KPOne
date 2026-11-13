import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/RegistrationReview/Show.vue');
const page = read('resources/js/pages/RegistrationReview/V2/Show.vue');
const config = read('config/v2-pages.php');
const loose = (s) => s.replace(/\s+/g, '');

test('V2 sends the same requests and keeps the same form keys as Classic', () => {
    for (const piece of [
        '`/registration-review/${props.intake.publicId}/correction-required`',
        '`/registration-review/${props.intake.publicId}/reject`',
        '`/registration-review/${props.intake.publicId}/correct`',
        '`/registration-review/${props.intake.publicId}/accept`',
        '/guardian-contact?patient_number=${encodeURIComponent(patientNumber)}',
        'lock_version: props.intake.lockVersion',
        'idempotency_key: props.visitOptions.idempotencyKey',
        "resolution: 'match'",
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
        assert.ok(loose(page).includes(loose(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 shows every Classic field of the correction form', () => {
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
        assert.ok(page.includes(`correction.${key}`), `V2 lacks ${key}`);
    }
});

test('V2 is registered, guarded and asks before rejecting', () => {
    assert.match(config, /'RegistrationReview\/Show'/);
    assert.ok(
        page.includes(
            '<V2LeaveGuard :dirty="dirty && !correction.processing" />',
        ),
    );
    assert.match(page, /Reject this intake\?/);
    assert.match(page, /@confirm="confirmReject"/);
    assert.ok(!/console\./.test(page));
});

test('V2 keeps the privacy rules: names only in the summary, no values logged', () => {
    assert.ok(page.includes("label: 'Unsaved corrections'"));
    assert.ok(page.includes('changedFields.value.join'));
    assert.ok(/role="alert"/.test(page));
    assert.ok(page.includes('are not used for WhatsApp'));
});
