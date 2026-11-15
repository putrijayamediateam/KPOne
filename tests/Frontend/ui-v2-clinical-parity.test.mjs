import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Clinical/Show.vue');
const composable = read(
    'resources/js/composables/clinical/useConsultationV2.ts',
);
const page = read('resources/js/pages/Clinical/V2/Show.vue');
const config = read('config/v2-pages.php');
const norm = (value) => value.replace(/\s+/g, '').replace(/[(),;]/g, '');

const classicBody = classic.slice(
    classic.indexOf('const user = computed'),
    classic.indexOf('</script>'),
);
const composableBody = composable.slice(
    composable.indexOf('const user = computed'),
    composable.indexOf('return {'),
);

test('the consultation page logic is an exact copy of Classic (form, drift, guard, hold, recall, treatment call)', () => {
    assert.equal(norm(composableBody), norm(classicBody));
});

test('the V2 page is listed for the V2 gate and keeps focus mode', () => {
    assert.match(config, /'Clinical\/Show'/);
    assert.match(page, /focusMode: true/);
});

test('the endpoints and payload keys are the ones in the shared logic, and the page adds none', () => {
    for (const fragment of [
        '/queue/recall',
        '/queue/treatment-call',
        '/encounter/${action}',
        'queue_lock_version',
        'encounter_lock_version',
        'visit_lock_version',
        'expected_branch_id',
        'idempotency_key',
    ]) {
        assert.ok(composable.includes(fragment), fragment);
    }

    assert.doesNotMatch(page, /router\.(get|post|put|patch|delete|visit)\(/);
    assert.doesNotMatch(page, /\.patch\(|\.post\(/);
});

test('the page uses V2 blocks and the V2 unsaved-work dialog, not the Classic dialog', () => {
    assert.match(page, /UnsavedWorkDialogV2/);
    assert.doesNotMatch(
        page,
        /UnsavedClinicalWorkDialog|OperationalConfirmDialog/,
    );
    assert.match(page, /<V2Tabs/);
    assert.match(page, /<V2StickyBar>/);
    assert.match(page, /<V2Chip/);
});

test('the four Classic Partials are reused unchanged', () => {
    for (const name of [
        'TreatmentPlanPanel',
        'AllergyProblemPanel',
        'ClinicalHistoryPanel',
        'DispensaryAttentionPanel',
    ]) {
        assert.match(page, new RegExp(`@/pages/Clinical/Partials/${name}.vue`));
    }
});

test('the V2 unsaved-work dialog has the Classic props and events; Save and hold is the filled choice', () => {
    const classicDialog = read(
        'resources/js/components/clinical/UnsavedClinicalWorkDialog.vue',
    );
    const v2Dialog = read(
        'resources/js/components/clinical-v2/UnsavedWorkDialogV2.vue',
    );

    for (const fragment of [
        "'save-and-hold': []",
        "'hold-without-saving': []",
        "processingAction: 'confirm' | 'secondary'",
        'unsavedItems: string[]',
    ]) {
        assert.ok(classicDialog.includes(fragment), fragment);
        assert.ok(v2Dialog.includes(fragment), fragment);
    }

    assert.match(v2Dialog, /Save and hold/);
    assert.match(
        v2Dialog,
        /variant="outline"[\s\S]{0,200}?Discard and hold|Discard and hold/,
    );
});

test('one Save on the page: filled only while there are unsaved changes', () => {
    assert.match(page, /form\.isDirty \? 'default' : 'outline'/);
});

test('the Treatment plan Complete button is the Classic one (Partial untouched), so no second page-level filled button is added', () => {
    assert.doesNotMatch(page, /Complete Consultation/);
});
