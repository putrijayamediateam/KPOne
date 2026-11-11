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

test('both read-only receipt lists show a status badge, not plain text', () => {
    for (const [name, row, field] of [
        ['Invoice', invoiceRow, 'payment'],
        ['Billing', readOnlyList, 'receipt'],
    ]) {
        assert.ok(row.includes('<StatusBadge'), `${name} uses StatusBadge`);
        assert.match(
            row,
            new RegExp(
                `:status="${field}\\.status"\\s+:tone="receiptTone\\(${field}\\.status\\)"`,
            ),
            `${name} passes the receipt tone`,
        );
        assert.doesNotMatch(
            row,
            /class="capitalize"/,
            `${name} drops the plain text`,
        );
        assert.doesNotMatch(
            row,
            /receivedAt \}\} ·/,
            `${name} drops the trailing separator`,
        );
    }
});

test('the badge sits on its own row before the refunded line', () => {
    for (const row of [invoiceRow, readOnlyList]) {
        const badge = row.indexOf('<StatusBadge');
        assert.ok(badge > row.indexOf('receivedAt }}'));
        assert.ok(badge < row.indexOf('payment-refunded'));
        assert.ok(
            row.lastIndexOf('<div class="mt-1">', badge) >
                row.indexOf('receivedAt }}'),
        );
    }
});

test('a reversed receipt stays grey on every Billing receipt badge', () => {
    for (const source of [invoice, workspace]) {
        assert.match(
            source,
            /const receiptTone = \(status: string\) =>\s+status === 'reversed' \? \('neutral' as const\) : undefined;/,
        );
    }

    const badges =
        workspace.match(
            /<StatusBadge\s+:status="receipt\.status"\s+:tone="receiptTone\(receipt\.status\)"/g,
        ) ?? [];
    assert.equal(badges.length, 3);
    assert.doesNotMatch(
        workspace,
        /<StatusBadge :status="receipt\.status" \/>/,
    );
});
