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

test('design round 2: ten rows, phone row pattern, overdue tones in words', () => {
    assert.match(page, /const dueCap = 10;/);
    assert.match(page, /Showing \{\{ visibleCycleRows\.length \}\} of/);
    assert.match(page, /row\.overdueDays >= 30/);
    assert.match(page, /'font-medium text-destructive'/);
    assert.match(
        page,
        /min-h-11 w-full rounded border px-3 disabled:opacity-40 sm:w-auto lg:min-h-9/,
    );
    assert.match(
        page,
        /inline-flex min-h-11 items-center px-2 underline lg:min-h-9/,
    );
});

test('STK-23: never counted is amber, red only from 30 days overdue, with a legend', () => {
    assert.match(page, /row\.overdueDays !== null && row\.overdueDays >= 30/);
    assert.match(
        page,
        /Amber: due or up to 29 days overdue\. Red: 30 days or more\./,
    );
});
