import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Reorder suggestions explains its method and writes nothing', () => {
    const page = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
    assert.match(
        page,
        /Suggested = reorder level − on hand − on order − incoming \+/,
    );
    assert.match(
        page,
        /rounded\s+up to whole packs and at least the supplier's minimum order\./,
    );
    assert.match(page, /Nothing is ordered from this page\./);
    // Read only: no form posts anything.
    assert.doesNotMatch(page, /useForm|router\.(post|put|patch|delete)/);
    assert.match(page, /router\.get\(\s*'\/inventory\/reorder-suggestions'/);
});

test('Reorder suggestions keeps cost and tap targets in line with the design rules', () => {
    const page = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
    assert.match(page, /v-if="suggestions\.canSeeCost && row\.supplier"/);
    assert.match(
        page,
        /Estimated costs are shown only to a Director or Finance Officer\./,
    );
    assert.match(
        page,
        /class="h-11 rounded-md border bg-background px-3 lg:h-9"/,
    );
    assert.match(page, /href="\/inventory\/supplier-terms"/);
});
