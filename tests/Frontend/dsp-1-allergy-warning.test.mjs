import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Dispensary/Show.vue');
const warning = read(
    'resources/js/pages/Dispensary/Partials/AllergyMatchWarning.vue',
);

test('the warning names the allergy, asks for the confirmation and says text matching can miss', () => {
    assert.ok(warning.includes('Possible allergy match'));
    assert.ok(warning.includes('match.allergen'));
    assert.ok(warning.includes('I have checked with the patient/doctor'));
    assert.ok(warning.includes('type="checkbox"'));
    assert.ok(warning.includes('It can miss an allergy'));
    assert.ok(warning.includes('always read the full allergy'));
    assert.ok(warning.includes('role="region"'));
    // UI-RESTYLE D4a (owner-approved): the danger token is readable in light and dark.
    assert.ok(
        warning.includes('border-destructive/40 bg-destructive/10'),
        'readable in dark mode',
    );
});

test('the warning appears on an edited line, a removed line being put back and a medicine being added', () => {
    const uses = page.match(/<AllergyMatchWarning/g) ?? [];
    assert.equal(uses.length, 3);
    assert.ok(page.includes('v-model="allergyChecks[checkKey(item)]"'));
    assert.ok(page.includes('v-model="addAllergyCheck"'));
    assert.ok(page.includes('data-testid="allergy-match-badge"'));
});

test('save, put back and add are disabled until the CA ticks, and the tick is sent with the save', () => {
    assert.ok(page.includes('needsAllergyCheck(item) ||'));
    assert.ok(page.includes('!addAllergyCheck) ||'));
    assert.ok(page.includes('allergy_confirmed_record_ids: matches.map('));
    assert.equal(
        (page.match(/\.\.\.allergyPayload\(/g) ?? []).length,
        3,
        'edit, put back and add all send the confirmation',
    );
});

test('the full allergy list stays visible on the Dispensary page', () => {
    assert.ok(page.includes('data-testid="dispensary-allergies"'));
    assert.ok(page.includes('data-testid="otc-allergy"'));
});

test('a tick only covers the allergies shown when it was ticked, and the add form reloads after a refusal', () => {
    assert.ok(page.includes('const checkKey = (item: Item) =>'));
    assert.ok(page.includes('void refreshChosen(medicine.publicId);'));
    assert.ok(page.includes('may match a recorded allergy. Tick'));
});
