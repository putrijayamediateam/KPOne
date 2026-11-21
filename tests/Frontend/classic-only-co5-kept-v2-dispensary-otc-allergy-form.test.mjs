// Classic only, CO-5: the Classic half of v2-dispensary-otc-allergy-form.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/Dispensary/Partials/OtcAllergyRecordForm.vue',
);
const classicPage = read('resources/js/pages/Dispensary/Show.vue');

test('Classic case page uses the Classic OTC allergy form', () => {
    assert.match(
        classicPage,
        /import OtcAllergyRecordForm from '@\/pages\/Dispensary\/Partials\/OtcAllergyRecordForm\.vue';/,
    );
    assert.ok(!/\/V2\//.test(classic));
});

test('Classic OTC allergy form keeps its fields and wording', () => {
    for (const fragment of [
        "form.setError('allergen_text', 'Enter the allergen.');",
        'form.reset();',
        "emit('saved');",
        'allergenInput.value?.focus();',
        'Medicine, food or substance, e.g. penicillin.',
        'What happened, e.g. rash, swelling.',
        "Saved to the patient's allergy profile. Only a doctor can change",
        'allergyCategoryOptions',
        'allergySeverityOptions',
        'maxlength="500"',
    ]) {
        assert.ok(classic.includes(fragment), `Classic ${fragment}`);
    }
});
