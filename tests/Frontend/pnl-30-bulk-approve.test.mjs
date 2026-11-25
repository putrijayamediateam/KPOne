// PNL-30: "Approve selected" on the Panel price changes page. One press, each proposal checked on its own.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/PanelPriceChanges/Index.vue');
const flat = (text) => text.replace(/\s+/g, ' ');

test('approvers can tick waiting rows proposed by someone else and approve them with one confirm', () => {
    assert.match(page, /data-testid="bulk-approve"/);
    assert.match(
        page,
        /selectable = computed\(\(\) =>\s*props\.waiting\.filter\(\(row\) => !row\.mine\)/,
    );
    assert.match(page, /Approve selected \(\{\{ selectedRows\.length \}\}\)/);
    assert.match(flat(page), /Confirm approval/);
    assert.match(flat(page), /Each one is checked on its own/);
    // One request for the batch, with each row's own lock version.
    assert.match(page, /'\/panel-price-changes\/approve-selected'/);
    assert.match(
        flat(page),
        /items: selectedRows\.value\.map\(\(row\) => \(\{ id: row\.id, lock_version: row\.lockVersion/,
    );
});

test('the bulk controls are for approvers only and every row checkbox has a name and a 44 px target', () => {
    assert.match(
        page,
        /v-if="tab === 'waiting' && canApprove && selectable\.length > 0"/,
    );
    assert.match(page, /v-if="tab === 'waiting' && canApprove && !row\.mine"/);
    assert.match(
        flat(page),
        /:aria-label="`Select \$\{row\.panelName\} \$\{row\.chargeName\}`"/,
    );
    assert.ok((page.match(/min-h-11/g) ?? []).length >= 4);
});

test('the page says when more are waiting than are shown and nothing is sent twice while busy', () => {
    assert.match(
        flat(page),
        /Showing the oldest \{\{ waiting\.length \}\} of \{\{ waitingCount \}\} waiting/,
    );
    assert.match(page, /if \(bulkActing\.value \|\|/);
});

test("the bulk panel sits before the empty state, so it never takes the list's v-else", () => {
    const bulk = page.indexOf('data-testid="bulk-approve"');
    const empty = page.indexOf('<EmptyState\n                v-if="(tab ===');
    const list = page.indexOf('<ul\n                v-else');
    assert.ok(bulk > 0 && empty > bulk && list > empty);
    // The only thing between the empty state and the list is the empty state itself.
    const between = page.slice(empty, list);
    assert.ok(!between.includes('bulk-approve'));
    assert.match(between, /\/>\s*$/);
});
