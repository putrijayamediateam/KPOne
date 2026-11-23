import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-32: the daily close pause and lock on Patient Billing and Daily cash closing.
// The server decides what is allowed; the page explains it (Daniel's look, 23 Nov 2026).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

const workspace = () =>
    read('resources/js/components/billing/BillingWorkspace.vue');
const notice = () => read('resources/js/components/billing/DayCloseNotice.vue');

test('BIL-32: the day-close state is a full-width banner at the top, above the three columns', () => {
    const page = workspace();
    const banner = page.indexOf('data-testid="day-close-banner"');
    const columns = page.indexOf('lg:grid-cols-[minmax(0,1.05fr)');
    assert.ok(banner > 0, 'banner present');
    assert.ok(banner < columns, 'banner comes before the three columns');
    assert.match(
        page,
        /<TriangleAlert class="mt-0\.5 size-4 shrink-0" aria-hidden="true" \/>/,
    );
    assert.match(page, /'This day is locked\. Only a refund is possible\.'/);
});

test('BIL-32: the locked receipts and Reverse and refund sit under the Payments card, not when paused', () => {
    const page = workspace();
    assert.ok(
        page.indexOf('<DayCloseNotice') >
            page.indexOf('data-testid="billing-payments"'),
        'the notice follows the Payments card',
    );
    assert.match(
        page,
        /<DayCloseNotice v-if=" billing\.dayClose && !billing\.dayClose\.paused && billing\.dayClose\.lockedReceipts\.length "/,
    );
});

test('BIL-32: a locked receipt has no separate Reverse recording error button', () => {
    const page = workspace();
    assert.equal(
        (
            page.match(
                /billing\.can\.reverse && receipt\.status === 'posted' && !lockedReceiptIds\.has\( ?receipt\.publicId,? ?\)/g,
            ) ?? []
        ).length,
        3,
    );
});

test('BIL-32: Reverse and record refund is the normal filled button, with what is missing under it', () => {
    const form = notice();
    assert.doesNotMatch(form, /variant="destructive"/);
    assert.match(form, /'Write why the receipt is reversed to continue\.'/);
    assert.match(form, /'Pick how the money was given back to continue\.'/);
    assert.match(form, /'Pick a refund reason to continue\.'/);
    assert.doesNotMatch(form, /placeholder=/);
});

test('BIL-32: tap targets stay 44 px below 1024 px', () => {
    const form = notice();
    assert.doesNotMatch(form, /sm:h-9/);
    assert.match(
        form,
        /h-11 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm lg:h-9/,
    );
});

test('BIL-32: Daily cash closing explains a waiting or a locked day', () => {
    const page = read('resources/js/pages/Billing/CashClosings.vue');
    assert.match(page, /data-testid="cash-closing-paused"/);
    assert.match(page, /data-testid="cash-closing-locked"/);
    assert.match(page, /Reason for recount \(needed to return\)/);
});

test('BIL-32: Reject is hidden for the refund of a locked day, with the reason shown', () => {
    const refunds = read('resources/js/components/billing/ReceiptRefunds.vue');
    assert.match(refunds, /v-if="refund\.canReject === false"/);
    assert.match(refunds, /can be approved but not rejected\./);
});
