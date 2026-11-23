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
        // Stock value and Medicine sales are not in the shared list: each layout adds them for their holders (asserted
        // below). STK-44 (owner-approved assertion change): Medicine sales joins this filter, so the count stays 16.
        .filter(
            (link) =>
                link !== '/inventory/stock-value=Stock value and cost' &&
                link !== '/inventory/medicine-sales=Medicine sales',
        );
    assert.equal(classicLinks.length, 16);
    assert.match(
        desktop,
        /href="\/inventory\/stock-value"\s+variant="outline"\s+v-if="referenceData\.canViewStockValue"[\s\S]*?>Stock value and cost<\/ActionLink/,
    );
    assert.match(
        desktop,
        /href="\/inventory\/medicine-sales"\s+variant="outline"\s+v-if="referenceData\.canViewMedicineSales"[\s\S]*?>Medicine sales<\/ActionLink/,
    );
});

test('Classic Inventory home keeps its props', () => {
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

test('Classic Inventory home shows a quantity with its stock unit', () => {
    assert.match(classic, /\{\{ row\.quantity \}\} \{\{ row\.stockUnit \}\}/);
});
