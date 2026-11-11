import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/GovernedStock.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the count page lists counts due by class and starts a count', () => {
    assert.match(page, /data-testid="counts-due"/);
    assert.match(page, /Counts due \(\{\{ stock\.cycle\.total \}\}\)/);
    assert.match(page, /controlled medicines are\s+always A\./);
    assert.match(page, /'\/inventory\/counts\/cycle'/);
    assert.match(page, /retry_key: crypto\.randomUUID\(\)/);
    assert.match(page, /'Never counted'/);
});

test('blind counts show no expected quantity or on hand while counting', () => {
    assert.match(page, /<p v-if="row\.blind" data-testid="blind-count">/);
    assert.match(page, /row\.quantity !== undefined/);
    assert.match(page, /the expected quantity is not shown/);
});
