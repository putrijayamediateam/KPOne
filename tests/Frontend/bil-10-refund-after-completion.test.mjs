import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-10 (owner decisions 2026-11-06): refunds after completion, partial amounts, invoice credit lines.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );
const refunds = read('resources/js/components/billing/ReceiptRefunds.vue');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');
const invoice = read('resources/js/pages/Billing/Invoice.vue');
const reconciliation = read(
    'resources/js/components/insights/SalesReconciliation.vue',
);

test('after completion a posted receipt takes a partial amount with its own reasons', () => {
    assert.ok(refunds.includes('Amount given back (RM)'));
    assert.ok(refunds.includes('amount_sen: toSen(f.amount)'));
    assert.ok(refunds.includes('refunds.afterCompletionReasons'));
    assert.ok(refunds.includes('left to refund'));
    assert.ok(
        refunds.includes(
            'Once approved, the invoice is lowered by the same amount, so nothing becomes due again.',
        ),
    );
    assert.ok(!refunds.includes('A refund after completion is not available'));
});

test('the Billing and Invoice pages show the refund credit line only when there is one', () => {
    assert.ok(workspace.includes('v-if="billing.invoice.refundCreditSen"'));
    assert.ok(workspace.includes('Refund credit'));
    assert.ok(invoice.includes('v-if="invoice.financial.refundCreditSen"'));
    assert.ok(invoice.includes('Refund credit'));
});

test('Insights shows refund credits as their own row, dated by approval', () => {
    assert.ok(reconciliation.includes('Refund credits'));
    assert.ok(reconciliation.includes('totals.refundCreditsSen'));
    assert.ok(
        reconciliation.includes(
            'Refund credits count on the day the refund after completion was approved.',
        ),
    );
});
