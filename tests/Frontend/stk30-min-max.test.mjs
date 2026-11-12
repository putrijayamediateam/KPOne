import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('STK-30: the reorder form sends an optional maximum and the list shows it', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );
    assert.match(panel, /max_level: reorder\.max,/);
    // Daniel: both boxes carry a visible label, and the maximum's message sits under its own box.
    assert.match(
        panel,
        /<label class="grid gap-1 text-sm"\s*>Reorder level\s*<Input/,
    );
    assert.match(
        panel,
        /<label class="grid gap-1 text-sm"\s*>Maximum \(optional\)\s*<Input/,
    );
    assert.match(panel, /<InputError\s+id="reorder-max-error"/);
    assert.match(panel, /reorderErrors\.max_level = errors\.max_level;/);
    assert.match(panel, /Leave\s+blank to remove any maximum\./);
    assert.match(
        panel,
        /<template v-if="row\.maxLevel !== null">\s*· max \{\{ row\.maxLevel \}\}/,
    );
});

test('STK-30: Reorder suggestions shows the maximum, or Not set, on every card', () => {
    const page = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
    assert.match(page, /maxLevel: string \| null;/);
    assert.match(
        page,
        /<dt class="text-muted-foreground">Maximum<\/dt>\s*<dd\s+v-if="row\.maxLevel !== null"/,
    );
    assert.match(
        page,
        /<dd v-else class="text-muted-foreground">Not set<\/dd>/,
    );
    assert.match(
        page,
        /Where\s+a\s+maximum is set, the maximum is used instead of the reorder level\./,
    );
});
