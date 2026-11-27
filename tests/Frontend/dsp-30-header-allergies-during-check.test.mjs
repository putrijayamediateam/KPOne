import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-30 (owner, 27 Nov 2026, option (a)): while the doctor's check waits, the allergy list shows read only.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const panel = read(
    'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
);
const types = read('resources/js/types/clinical.ts');

test('a read-only list says why, and the edit controls still follow canUpdate and canReview', () => {
    assert.match(
        panel,
        /v-if="allergies\.readOnlyReason === 'doctor_check'"[\s\S]{0,200}Read only while the medicine waits for your check\./,
    );
    assert.match(types, /readOnlyReason\?: 'doctor_check';/);
    assert.match(panel, /v-if="allergies\.canUpdate"/);
    assert.match(panel, /allergies\.canReview &&/);
});

test('the medicine search stays closed while the list is read only', () => {
    const plan = read(
        'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
    );
    assert.match(
        plan,
        /const medicineSearchOpen = computed\(\s*\(\) => allergyReady\.value && props\.allergies\?\.readOnlyReason === undefined,?\s*\);/,
    );
    assert.match(
        plan,
        /:disabled="searchingMedicine \|\| !medicineSearchOpen"/,
    );
    assert.match(
        plan,
        /if \(kind === 'medicines' && !medicineSearchOpen\.value\) \{\s*return;\s*\}/,
    );
});
