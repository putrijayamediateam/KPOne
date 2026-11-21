// Classic only, CO-10: the Classic half of bil-25-receipt-status-badges, kept when the V2 receipt badge was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const invoice = read('resources/js/pages/Billing/Invoice.vue');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');

const invoiceRow = invoice
    .split('data-testid="invoice-payments"')[1]
    .split('</ul>')[0];
const readOnlyList = workspace
    .split('v-if="!manage && billing.payments.length"')[1]
    .split('</ul>')[0];

test('the Invoice and read-only Billing receipt rows show "time · status" as plain text', () => {
    for (const [name, row, field] of [
        ['Invoice', invoiceRow, 'payment'],
        ['Billing', readOnlyList, 'receipt'],
    ]) {
        const flat = row.replace(/\s+/g, ' ');
        assert.ok(
            flat.includes(
                `{{ ${field}.receivedAt }} <span >· <span class="capitalize">{{ ${field}.status }}</span></span >`,
            ),
            `${name}: plain "time · status"`,
        );
        assert.doesNotMatch(row, /<StatusBadge|v2:/);
    }
});

test('a reversed receipt stays grey on the Billing receipt badges', () => {
    assert.match(
        workspace,
        /const receiptTone = \(status: string\) =>\s+status === 'reversed' \? \('neutral' as const\) : undefined;/,
    );

    assert.doesNotMatch(
        workspace,
        /<StatusBadge :status="receipt\.status" \/>/,
    );
});
