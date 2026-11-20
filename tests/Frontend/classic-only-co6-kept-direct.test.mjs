import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Classic only, CO-6: the Classic half of stk28-stock-value.test.mjs ('Classic and the new layout show the same report
// from the same props'), kept when the V2 Stock value page was removed. Assertions copied unchanged; the V2 registry
// and props-equality lines are dropped.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/StockValue.vue');

test('STK-28: the Classic Stock value page keeps its report route, journal download, banner, tabs and page size', () => {
    // Classic no longer asks the Director to turn on the new layout.
    assert.doesNotMatch(classic, /\/spaces\/preference|Turn on the new layout/);
    assert.match(classic, /defineProps<\{ report: Report \}>\(\)/);
    assert.match(classic, /router\.get\(\s*'\/inventory\/stock-value'/);
    assert.equal(
        (classic.match(/\/inventory\/stock-value\/journal/g) ?? []).length,
        1,
    );
    // The banner shows above the summary and on every tab.
    const classicTemplate = classic.slice(classic.indexOf('<template>'));
    assert.ok(
        (classicTemplate.match(/\{\{ report\.banner \}\}/g) ?? []).length >= 3,
    );
    assert.ok(
        classicTemplate.indexOf('valuation-banner') <
            classicTemplate.indexOf('valuation-summary'),
    );

    // The same four tabs and 25 rows a page; nothing exported beyond the CSV.
    for (const label of [
        'Stock value',
        'Cost and margin',
        'Losses',
        'How this is counted',
    ]) {
        assert.match(classic, new RegExp(`label: '${label}'`));
    }

    assert.match(classic, /const PAGE_SIZE = 25;/);
    assert.doesNotMatch(classic, /\.xlsx|\.pdf/i);
    // The Stock value page keeps its quantity wording from the shared Classic library (CO-3).
    assert.match(classic, /from '@\/lib\/inventory-words'/);
});
