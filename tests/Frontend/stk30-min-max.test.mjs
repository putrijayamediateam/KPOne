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
    assert.match(panel, /aria-label="Maximum \(optional\)"/);
    assert.match(panel, /Leave\s+blank to remove any maximum\./);
    assert.match(
        panel,
        /<template v-if="row\.maxLevel !== null">\s*· max \{\{ row\.maxLevel \}\}/,
    );
});

test('STK-30: Reorder suggestions shows the maximum only when one is set', () => {
    const page = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
    assert.match(page, /maxLevel: string \| null;/);
    assert.match(
        page,
        /<div v-if="row\.maxLevel !== null">\s*<dt class="text-muted-foreground">Maximum<\/dt>/,
    );
    assert.match(
        page,
        /Where\s+a\s+maximum is set, the maximum is used instead of the reorder level\./,
    );
});
