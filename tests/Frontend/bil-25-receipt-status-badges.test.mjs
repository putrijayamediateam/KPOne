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

test('Version 2 shows a status badge; Classic keeps the plain status text (owner decision 2026-11-13)', () => {
    for (const [name, row, field] of [
        ['Invoice', invoiceRow, 'payment'],
        ['Billing', readOnlyList, 'receipt'],
    ]) {
        const flat = row.replace(/\s+/g, ' ');
        assert.ok(
            flat.includes(
                `{{ ${field}.receivedAt }} <span class="v2:hidden" >· <span class="capitalize">{{ ${field}.status }}</span></span >`,
            ),
            `${name}: Classic keeps "time · status" as plain text, hidden in Version 2`,
        );
        assert.match(
            row,
            new RegExp(
                `<div class="mt-1 hidden v2:block">\\s*<StatusBadge\\s+:status="${field}\\.status"\\s+:tone="receiptTone\\(${field}\\.status\\)"`,
            ),
            `${name}: the badge row shows only in Version 2`,
        );
    }
});

test('the badge sits on its own row before the refunded line, with Version 2-only spacing', () => {
    for (const row of [invoiceRow, readOnlyList]) {
        const badge = row.indexOf('<StatusBadge');
        assert.ok(badge > row.indexOf('receivedAt }}'));
        assert.ok(badge < row.indexOf('payment-refunded'));
    }

    // Classic spacing of the read-only Billing list is unchanged; the gap under the badge is Version 2 only.
    assert.ok(
        readOnlyList.includes(
            'class="text-xs text-muted-foreground tabular-nums v2:mt-1"',
        ),
    );
    assert.ok(
        readOnlyList.includes(
            'class="text-[11px] break-words text-muted-foreground v2:mt-1"',
        ),
    );
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
