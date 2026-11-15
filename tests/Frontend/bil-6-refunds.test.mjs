import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const path = 'resources/js/components/billing/ReceiptRefunds.vue';
const raw = read(path);
const refunds = raw.replace(/\s+/g, ' ');
const workspace = read(
    'resources/js/components/billing/BillingWorkspace.vue',
).replace(/\s+/g, ' ');
const closing = read('resources/js/pages/Billing/CashClosings.vue').replace(
    /\s+/g,
    ' ',
);

test('the refunds panel compiles and is shown only when the server sends it', () => {
    const descriptor = parse(raw, { filename: path }).descriptor;
    const result = compileTemplate({
        id: 'test-refunds',
        filename: path,
        source: descriptor.template?.content ?? '',
    });
    assert.deepEqual(result.errors, []);
    assert.ok(workspace.includes('<ReceiptRefunds v-if="billing.refunds"'));
});

test('a refund is recorded for a reversed receipt and decided by someone else', () => {
    assert.ok(refunds.includes('`${props.base}/refunds/${receipt}`'));
    assert.ok(
        refunds.includes('`${props.base}/refunds/${refund}/${decision}`'),
    );
    assert.ok(refunds.includes('v-if="receipt.canRecord"'));
    assert.ok(refunds.includes('v-if="refund.canDecide"'));
    assert.ok(
        refunds.includes(
            'You recorded this refund. Someone else must approve it.',
        ),
    );
    // BIL-10 (owner-approved test change): refunds after completion are now available.
    assert.ok(
        refunds.includes(
            'Once approved, the invoice is lowered by the same amount, so nothing becomes due again.',
        ),
    );
});

test('reject needs a reason and every refusal reason is shown', () => {
    assert.ok(refunds.includes('!rejectReason[refund.publicId]'));

    for (const key of [
        'errors.value.refund',
        'errors.value.reason_code',
        'errors.value.payment_method_id',
        'errors.value.idempotency_key',
    ]) {
        assert.ok(refunds.includes(key), key);
    }

    // The error shows beside the receipt that sent the request.
    assert.ok(refunds.includes('v-if="lastSent === receipt.publicId"'));
});

test('the recorder is told who approves, and buttons name the refund', () => {
    assert.ok(refunds.includes('data-testid="refund-recorder-hint"'));
    assert.ok(refunds.includes('Waiting for a Finance Officer or Director'));
    assert.ok(refunds.includes('`Approve refund of ${myr(refund.amountSen)}`'));
    assert.ok(refunds.includes('`Reject refund of ${myr(refund.amountSen)}`'));
    assert.ok(closing.includes('or a refund was recorded'));
});

test('cash closing accepts and shows amounts below zero after refunds', () => {
    assert.ok(closing.includes("replace('\\u2212', '-')"));
    assert.ok(closing.includes('inputmode="text"'));
    assert.ok(closing.includes('const money = (sen: number) => (sen < 0 ?'));
    assert.ok(closing.includes('data-testid="cash-refunds"'));
    assert.ok(closing.includes('enter a minus amount'));
});
