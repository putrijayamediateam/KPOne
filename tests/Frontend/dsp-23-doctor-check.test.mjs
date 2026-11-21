import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const actions = read(
    'resources/js/components/dispensary/DoctorCheckActions.vue',
);
const panel = read(
    'resources/js/pages/Clinical/Partials/DispensaryCheckPanel.vue',
);
const clinical = read('resources/js/pages/Clinical/Show.vue');
const show = read('resources/js/pages/Dispensary/Show.vue');
const list = read('resources/js/pages/Dispensary/DoctorChecks.vue');
const settings = read('resources/js/pages/Dispensary/Settings.vue');
const registration = read('resources/js/pages/Registration/Index.vue');

test('DSP-23: Verify and Send back are outline buttons; red appears only in the Verify confirm sheet', () => {
    assert.equal((actions.match(/variant="outline"/g) ?? []).length, 2);
    assert.match(actions, /title="Verify and release to billing\?"/);
    assert.match(
        actions,
        /description="The medicine is dispensed when you confirm\."/,
    );
    assert.match(
        actions,
        /title="Verify and release to billing\?"[\s\S]*?\bdestructive\b/,
    );
    assert.doesNotMatch(actions, /variant="destructive"/);
});

test('DSP-23: the Send back picker starts empty and stays off until a reason is chosen', () => {
    assert.match(actions, /const reason = ref\(''\);/);
    assert.match(actions, /'Pick a reason to continue\.'/);
    assert.match(actions, /:confirm-disabled="sendBackDisabledReason !== ''"/);

    for (const label of [
        'Wrong medicine',
        'Wrong quantity',
        'Wrong batch',
        'Label problem',
        'Other',
    ]) {
        assert.ok(actions.includes(`label: '${label}'`), label);
    }

    assert.match(actions, /'Add a short note for "Other"\.'/);
});

test('DSP-23: a CA Supervisor gives a reason before verifying instead of the doctor', () => {
    assert.match(actions, /Why are you checking instead of the doctor\?/);

    for (const value of ['doctor_off_shift', 'doctor_emergency']) {
        assert.ok(actions.includes(`value: '${value}'`), value);
    }

    assert.match(actions, /retry_key: retryKey\.value/);
    assert.match(show, /<DoctorCheckActions[\s\S]*?as-supervisor/);
    assert.match(
        show,
        /You prepared this case, so someone else must check it\./,
    );
});

test('DSP-23: the doctor sees "Medicine ready for your check" with the order beside what was prepared', () => {
    assert.match(
        clinical,
        /<DispensaryCheckPanel[\s\S]*?v-if="clinical\.dispensaryCheck"/,
    );
    assert.match(panel, /Medicine ready for your check/);
    assert.match(panel, /Ordered/);
    assert.match(panel, /Prepared/);
    assert.match(panel, /'No known allergies'/);
    // Daniel's note 3: CA changes are a dot and a word, not colour alone.
    assert.match(panel, /edited: 'Edited by CA'/);
    assert.match(panel, /added: 'Added by CA'/);
    assert.match(panel, /class="size-1\.5 rounded-full bg-foreground\/70"/);
});

test('DSP-23: the CA page sends to the doctor, shows the waiting banner and the sent-back reason', () => {
    assert.match(show, /'Send to doctor for check'/);
    assert.match(
        show,
        /caseAction\('send-for-check', \{ retry_key: crypto\.randomUUID\(\) \}\)/,
    );
    assert.match(
        show,
        /Waiting for \{\{ dispensary\.doctor \?\? 'the doctor' \}\}'s check/,
    );
    assert.match(
        show,
        /Do not hand over the medicine until the doctor has checked it\./,
    );
    assert.match(show, /data-testid="doctor-check-call-help"/);
    assert.match(
        show,
        /border-warning\/40 bg-warning\/10 p-4 text-sm text-warning-strong"\s+role="status"\s+data-testid="doctor-check-sent-back"/,
    );
    assert.doesNotMatch(
        show,
        /data-testid="doctor-check-sent-back"[^>]*destructive/,
    );
});

test('DSP-23: the Registration board names the waiting state, and the branch list and switch exist', () => {
    assert.match(registration, /"Waiting for doctor's check"/);
    assert.match(list, /<PageHeader title="Waiting for doctor's check"/);
    assert.match(
        list,
        /You prepared this case, so someone else must check it\./,
    );
    assert.match(settings, /role="switch"/);
    assert.match(settings, /class="relative inline-flex h-11/);
    assert.match(settings, /on \? 'On' : 'Off'/);
    assert.match(settings, /retry_key: crypto\.randomUUID\(\)/);
});
