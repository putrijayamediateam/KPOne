import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const pagePath = 'resources/js/pages/Billing/PayLaterCollections.vue';
const raw = read(pagePath);
const page = raw.replace(/\s+/g, ' ');
const outstanding = read(
    'resources/js/components/billing/OutstandingBills.vue',
).replace(/\s+/g, ' ');

test('the pay-later collection page compiles', () => {
    const descriptor = parse(raw, { filename: pagePath }).descriptor;
    const result = compileTemplate({
        id: 'test-pay-later',
        filename: pagePath,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
});

test('the page shows totals, the overdue filter and a link to each billing page', () => {
    // Owner OK 2026-11-11 (BIL-23): the page is renamed "Bills to collect".
    assert.ok(page.includes('title="Bills to collect"'));
    assert.ok(page.includes('data-testid="pay-later-totals"'));
    assert.ok(page.includes('Show only bills past their due date'));
    assert.ok(page.includes(':href="row.url"'));
    // Owner OK 2026-11-10 (BIL-18): a proper plural instead of "day(s)".
    assert.ok(
        page.includes("row.daysOverdue === 1 ? 'day' : 'days' }} past due"),
    );
    assert.ok(page.includes("'/pay-later-collections'"));
});

test('the page records nothing: no form, post, put, patch or delete', () => {
    assert.ok(
        !/<form|router\.(post|put|patch|delete)|method="post"/i.test(page),
    );
});

test('the Outstanding bill box links the list only when the server sends the link', () => {
    assert.ok(outstanding.includes('v-if="collectionsUrl"'));
    assert.ok(outstanding.includes(':href="collectionsUrl"'));
    assert.ok(outstanding.includes('?.payLaterCollectionsUrl ?? null'));
    assert.ok(
        outstanding.includes('View all pay-later to collect at this branch'),
    );
});
