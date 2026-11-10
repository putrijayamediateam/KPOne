import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-17: display-only fixes on the Billing page and the pay-later collection list.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');
const collections = read('resources/js/pages/Billing/PayLaterCollections.vue');

test('a fully paid pay-later reads Paid in full, and a part-paid one shows what is left', () => {
    assert.ok(workspace.includes('data-testid="pay-later-settled"'));
    assert.ok(workspace.includes('Paid in full · nothing left to collect'));
    assert.ok(workspace.includes('billing[kind]!.remainingSen === 0'));
    assert.ok(workspace.includes('still to collect ·'));
});

test('voided invoices stay visible with their receipts', () => {
    assert.ok(workspace.includes('data-testid="voided-invoices"'));
    assert.ok(workspace.includes('v-for="voided in billing.voidedInvoices"'));
    assert.ok(workspace.includes('They no longer count towards what is owed.'));
});

test('the amount-due shortcut is offered on a completed visit only when money is due', () => {
    assert.ok(
        workspace.includes(
            '(!isCompleted || (billing.invoice?.state.due_now ?? 0) > 0)',
        ),
    );
});

test('the pay-later list notes money due now, which is paid first', () => {
    assert.ok(collections.includes('data-testid="pay-later-due-now"'));
    assert.ok(collections.includes('collections.dueNow?.[row.visitNumber]'));
    assert.ok(collections.includes('due now: collect this first'));
});

test('voided invoices use the shared status badge (Siti)', () => {
    assert.ok(
        workspace.includes('<StatusBadge status="voided" tone="neutral"'),
    );
});
