import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BV2-4: Version 2 Manage payments is the V2 Patient Billing page with
// `manage`, as Classic's Payments page is BillingWorkspace with `manage`.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const payments = read('resources/js/pages/Billing/V2/Payments.vue');
const show = read('resources/js/pages/Billing/V2/Show.vue');
const flat = show.replace(/\s+/g, ' ');

test('BV2-4: registered, same prop, renders the V2 billing page in manage mode', () => {
    assert.match(read('config/v2-pages.php'), /'Billing\/Payments',/);
    assert.match(payments, /defineProps<\{ billing: BillingPage \}>\(\)/);
    assert.match(payments, /<BillingShowV2 :billing="billing" manage \/>/);
    assert.match(
        read('resources/js/pages/Billing/Payments.vue'),
        /<BillingWorkspace :billing="billing" manage/,
    );
});

test('BV2-4: every action and print returns to Manage payments', () => {
    assert.match(
        read('resources/js/composables/billing/useBillingWorkspaceV2.ts'),
        /return_to: props\.manage \? 'payments' : 'billing'/,
    );
    assert.doesNotMatch(show, /return-to="billing"/);
    assert.equal(
        [...flat.matchAll(/:return-to=" ?manage \? 'payments' : 'billing' ?"/g)]
            .length,
        3,
    );
});

test('BV2-4: title, crumbs and back link name Payments', () => {
    assert.match(flat, /props\.manage \? 'Payments'/);
    assert.match(flat, /\{ title: 'Payments' \}/);
    assert.match(
        flat,
        /<ActionLink v-if="manage" :href="base" variant="outline" class="max-lg:min-h-11" data-testid="payments-back-to-billing" >← Back to billing<\/ActionLink >/,
    );
});

test('BV2-4: payment action only; no build, finalize, complete or void', () => {
    assert.match(flat, /props\.manage && kind !== 'pay' && kind !== 'settle'/);
    assert.match(
        flat,
        /\.filter\(\(group\) => !\(props\.manage && group\.label === 'Danger'\)\)/,
    );
    assert.match(flat, /!manage && billing\.invoice && billing\.can\.build/);
});

test('BV2-4: receipts and prints instead of the items; the Classic-hidden blocks stay hidden', () => {
    assert.match(show, /if \(props\.manage\) \{\s*tab\.value = 'documents';/);
    assert.match(flat, /<div v-if="!manage" class="p-3"> <V2Tabs/);
    assert.match(flat, /Receipts and prints/);

    for (const hidden of [
        '<section v-if="!manage" :class="[card, \'p-4 sm:p-5\']">',
        '<InvoiceActivityDrawer v-if="!manage"',
        '<OutstandingBills v-if="!manage"',
        'v-if="!manage && billing.panelAdjustment"',
        'v-if="!manage && (billing.billTo || billing.notes)"',
        'v-if="!manage && !billing.payments.length"',
        '<ul v-else-if="!manage" class="divide-y">',
        'billing.invoice && (!manage || billing.can.pay || billing.can.panelPropose || billing.can.deferPropose)',
    ]) {
        assert.ok(flat.includes(hidden), hidden);
    }

    // Refunds and receipt corrections stay, as on Classic's Payments page.
    assert.match(flat, /<ReceiptRefundsV2/);
    assert.match(flat, /<ReceiptCorrections/);
});

test('BV2-4 review (Daniel): Approve refund is outline; Correct a receipt matches the Refunds sizes', () => {
    const refunds = read(
        'resources/js/components/billing/v2/ReceiptRefundsV2.vue',
    ).replace(/\s+/g, ' ');
    assert.match(
        refunds,
        /<Button variant="outline" class="max-lg:min-h-11" :aria-label="`Approve refund of/,
    );
    assert.match(
        show,
        /\[data-testid='receipt-corrections'\] \{\s*font-size: 15px;/,
    );
    assert.match(show, /font-size: 14px;\s*line-height: 20px;/);
});
