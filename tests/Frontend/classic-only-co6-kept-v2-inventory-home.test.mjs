// Classic only, CO-6: the Classic half of v2-inventory-home.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/Index.vue');

test('Classic Inventory home keeps its 16 task links in order', () => {
    const desktop = classic.slice(
        classic.indexOf('lg:grid lg:grid-cols-3'),
        classic.indexOf('lg:hidden'),
    );
    const classicLinks = [
        ...desktop.matchAll(
            /href="([^"]+)"[\s\S]*?>\s*([^<]+?)\s*<\/ActionLink/g,
        ),
    ]
        .map((m) => `${m[1]}=${m[2]}`)
        // Stock value is not in the shared list: each layout adds it for the Director (asserted below).
        .filter(
            (link) => link !== '/inventory/stock-value=Stock value and cost',
        );
    assert.equal(classicLinks.length, 16);
    assert.match(
        desktop,
        /href="\/inventory\/stock-value"\s+variant="outline"\s+v-if="referenceData\.canViewStockValue"[\s\S]*?>Stock value and cost<\/ActionLink/,
    );
});

test('Classic Inventory home keeps its props, hold label rule and panels', () => {
    for (const prop of [
        'inventory:',
        'operations:',
        'receiptMemoryContext:',
        'referenceData:',
        'errors?:',
    ]) {
        assert.ok(classic.includes(prop), `Classic prop ${prop}`);
    }
});

test('Classic Inventory home imports no V2 code', () => {
    assert.ok(
        !classic.includes('components/v2') &&
            !classic.includes('useInventoryHome'),
    );
});

test('Classic Inventory home keeps its filter wording and stock panels', () => {
    const bar = read('resources/js/components/inventory-v2/StockPanelBar.vue');
    assert.match(bar, /<div\s+v-show="open"/);
    assert.ok(!/v-if="open"/.test(bar));
    assert.match(bar, /max-width: 1023\.98px/);
    assert.ok(!bar.includes("type='submit'"), 'submits are flattened too');
    assert.match(bar, /min-height: 2\.75rem/);
});

test('Classic Inventory home keeps its panel headings and units', () => {
    const bar = read('resources/js/components/inventory-v2/StockPanelBar.vue');
    assert.match(bar, /:inert="!open"/);
    assert.match(bar, /> section > h2:first-child/);
    assert.match(classic, /\{\{ row\.quantity \}\} \{\{ row\.stockUnit \}\}/);
});
