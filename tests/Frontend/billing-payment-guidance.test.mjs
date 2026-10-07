import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const path = 'resources/js/pages/Billing/Show.vue';
const raw = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
// Whitespace is collapsed so Prettier's line wrapping cannot break an assertion.
const billing = raw.replace(/\s+/g, ' ');

test('the billing page still compiles with the payment guidance', () => {
    const descriptor = parse(raw, { filename: path }).descriptor;
    const result = compileTemplate({
        id: 'test-billing-guidance',
        filename: path,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
});

test('the payment and the Panel amount are separate fields, each used by its own action', () => {
    assert.ok(billing.includes('Amount received (MYR)'));
    assert.ok(billing.includes('Amount the Panel or pay later will cover'));
    assert.ok(billing.includes('v-model="form.amount"'));
    assert.ok(billing.includes('v-model="form.responsibility_amount"'));
    // Add Payment still reads the payment amount; Propose reads the Panel amount.
    assert.match(
        billing,
        /const payment = \(\) => \{ const sen = toSen\(form\.amount\);/,
    );
    assert.match(
        billing,
        /const propose = \(kind: string\) => \{ const sen = toSen\(form\.responsibility_amount\);/,
    );
    assert.ok(!billing.includes('amount_sen: toSen'));
});

test('the page explains how to settle, how Panel payment works and what is waiting on approval', () => {
    assert.ok(billing.includes('How to settle this invoice.'));
    assert.ok(billing.includes('Pay by Panel or pay later'));
    assert.ok(billing.includes('data-testid="panel-steps"'));
    assert.ok(billing.includes('Press Propose Panel responsibility.'));
    assert.ok(billing.includes('Due Now then drops by the Panel amount.'));
    assert.ok(
        billing.includes(
            'This only sends the request for approval. The Panel amount counts after it is approved.',
        ),
    );
    assert.ok(billing.includes('Check the details, then press Approve.'));
    assert.ok(billing.includes('Waiting for approval.'));
    assert.ok(
        billing.includes('Choose a payment method to enable Add Payment.'),
    );
});

test('the entry form is cleared with both amounts and the use-amount-due shortcuts fill them', () => {
    assert.ok(billing.includes("responsibility_amount: '',"));
    assert.ok(billing.includes("form.responsibility_amount = '';"));
    assert.ok(billing.includes('@click="form.amount = dueNowText"'));
    assert.ok(
        billing.includes('@click="form.responsibility_amount = dueNowText"'),
    );
});
