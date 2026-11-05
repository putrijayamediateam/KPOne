import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const catalogue = read('resources/js/pages/Medicine/Index.vue');
const warning = read(
    'resources/js/pages/Dispensary/Partials/AllergyMatchWarning.vue',
);

test('catalogue editors edit drug groups in a dialog and the list shows them', () => {
    assert.ok(catalogue.includes('allergyGroups: string[];'));
    assert.ok(catalogue.includes('data-action="edit-allergy-groups"'));
    assert.ok(catalogue.includes('data-field="allergy-groups"'));
    assert.ok(catalogue.includes('data-testid="allergy-groups"'));
    assert.ok(
        catalogue.includes('KPOne does not'),
        'KPOne ships no clinical list',
    );
});

test('the save sends the list the editor saw and shows a refusal', () => {
    assert.ok(
        catalogue.includes('`/medicines/${row.publicId}/allergy-groups`'),
    );
    assert.ok(
        catalogue.includes('expected_allergy_groups: row.allergyGroups,'),
    );
    assert.ok(catalogue.includes('errors.allergy_groups ??'));
});

test('the Dispensary warning says drug groups are checked and can still miss', () => {
    assert.ok(warning.includes('catalogue drug groups only'));
    assert.ok(warning.includes('It can miss an allergy'));
});
