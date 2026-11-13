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

test('V2 blocks Save on an unreadable date and says what is missing before Accept', () => {
    assert.ok(page.includes('V2DateField'));
    assert.ok(page.includes('@update:invalid'));
    assert.match(page, /if \(dobInvalid\.value\)/);
    assert.ok(
        page.includes('Choose Match or Create for the duplicate to continue.'),
    );
    assert.ok(page.includes('Tick the coverage confirmation to continue.'));
    assert.ok(page.includes('v2-accept-reason-rail'));
    assert.ok(page.includes('v2-accept-reason-bar'));
    assert.ok(page.includes('Relationship: not chosen yet.'));
    // Only the dialog's confirm is red: the trigger stays an outline button.
    assert.ok(!/border border-destructive px-4/.test(page));
});

test('V2 starts on Create new patient only when there is no duplicate candidate', () => {
    assert.match(
        page,
        /resolution:\s*props\.intake\.duplicateCandidates\.length === 0\s*\?\s*'create'\s*:\s*'match'/,
    );
    // Classic keeps its own default: nothing is preselected there.
    assert.ok(classic.includes("resolution: 'match'"));
    // The payload keeps the same key; the person can still choose Match.
    assert.ok(page.includes('v-model="acceptance.resolution"'));
    assert.ok(
        page.includes("{ value: 'match', label: 'Match existing patient' }"),
    );
});

test('V2 hides the duplicate tick with no candidate and reloads them after a duplicate refusal', () => {
    assert.ok(
        loose(page).includes(
            loose(
                'intake.duplicateCandidates.length || acceptance.errors.duplicate_override',
            ),
        ),
    );
    assert.ok(page.includes('if (errors.duplicate_override)'));
    assert.ok(page.includes("router.reload({ only: ['intake'] })"));
    assert.ok(page.includes('I reviewed the possible duplicates'));
    assert.ok(!/Confirm creation only after duplicate/.test(page));
    assert.ok(!/server still enforces/i.test(page));
});

test('V2 explains a refusal the list does not show, and sends the person to the candidate above otherwise', () => {
    assert.ok(
        page.includes(
            'The check at accept found a possible match that is not listed here.',
        ),
    );
    assert.ok(page.includes('No soft or exact duplicate candidate was found.'));
    assert.ok(loose(page).includes(loose('Review it above, tick the box')));
    assert.ok(!page.includes('Check the Patient directory'));
});
