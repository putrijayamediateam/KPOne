import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const table = read('resources/js/components/ui/table/OperationalTable.vue');
const pager = read(
    'resources/js/components/ui/pagination/CompactPagination.vue',
);
const css = read('resources/css/app.css');

test('0.5: table is a 14px hairline frame with a muted sentence-case header and no shadow', () => {
    assert.match(table, /rounded-\[14px\]/);
    assert.match(table, /bg-muted text-\[13px\]/);
    assert.doesNotMatch(table, /shadow|uppercase/);
});
test('0.5: rows keep a 44px floor and numbers use tabular figures', () => {
    assert.match(table, /min-h-11/);
    assert.match(table, /tabular-nums/);
});
test('0.5: phone cards are opt-in and use data-label', () => {
    assert.match(table, /cards\?: boolean/);
    assert.match(css, /\.kp-table-cards/);
    assert.match(css, /attr\(data-label\)/);
});
test('0.5: pager stacks the label above 44px buttons on phones', () => {
    assert.match(pager, /grid-cols-2/);
    assert.match(pager, /col-span-2/);
    assert.match(pager, /max-lg:h-11/);
});
