import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const catalogue = read('resources/js/pages/Medicine/Index.vue');
const warning = read(
    'resources/js/pages/Dispensary/Partials/AllergyMatchWarning.vue',
);
const flat = (text) => text.replace(/\s+/g, ' ');

test('catalogue editors edit other names in their own dialog and the list shows them (DSP-1c)', () => {
    assert.ok(catalogue.includes('allergyAliases: string[];'));
    assert.ok(catalogue.includes('data-action="edit-allergy-aliases"'));
    assert.ok(catalogue.includes('data-field="allergy-aliases"'));
    assert.ok(catalogue.includes('data-testid="allergy-aliases"'));
    assert.ok(catalogue.includes('data-action="save-allergy-aliases"'));
    assert.ok(catalogue.includes('<DialogTitle>Other names</DialogTitle>'));
    assert.ok(
        flat(catalogue).includes(
            'They are never printed on drug labels and do not change the medicine name.',
        ),
    );
});

test('the save sends the list the editor saw and shows a refusal', () => {
    assert.ok(
        catalogue.includes('`/medicines/${row.publicId}/allergy-aliases`'),
    );
    assert.ok(
        catalogue.includes('expected_allergy_aliases: row.allergyAliases,'),
    );
    assert.ok(catalogue.includes('errors.allergy_aliases ??'));
});

test('the Dispensary warning says other names are checked and can still miss', () => {
    assert.ok(
        flat(warning).includes(
            'generic name, catalogue other names and catalogue drug groups only.',
        ),
    );
    assert.ok(flat(warning).includes('spelling not listed in the catalogue'));
});

test('both catalogue dialogs keep typed text on a validation error and close only on a stale list (DD-37)', () => {
    for (const [error, field] of [
        ['groupsError', 'medicine-allergy-groups'],
        ['aliasesError', 'medicine-allergy-aliases'],
    ]) {
        assert.ok(catalogue.includes(`const ${error} = ref('');`));
        assert.ok(catalogue.includes(`${error}.value = message;`));
        assert.ok(catalogue.includes(`id="${field}-error"`));
        assert.ok(catalogue.includes(`aria-describedby="${field}-error"`));
    }

    assert.equal(
        (
            catalogue.match(
                /message\.startsWith\('This medicine changed\.'\)/g,
            ) ?? []
        ).length,
        2,
    );
});
