import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const print = read('resources/js/pages/Billing/Print.vue');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');
const invoicePage = read('resources/js/pages/Billing/Invoice.vue');
const button = read('resources/js/components/billing/IssuePrintButton.vue');
const copies = read('resources/js/components/billing/IssuedCopies.vue');

test('the print page shows the issued label or a PREVIEW line', () => {
    assert.ok(print.includes('data-testid="issued-label"'));
    assert.ok(print.includes('data-testid="preview-label"'));
    assert.ok(print.includes('PREVIEW — not an issued copy'));

    for (const label of [
        "'ORIGINAL'",
        "'COPY'",
        '`REVISED (version ${props.issued.version})`',
    ]) {
        assert.ok(print.includes(label), label);
    }

    assert.ok(print.includes('{{ issued.backLabel }}'));
});

test('Billing print buttons record the print instead of opening the live page', () => {
    assert.doesNotMatch(workspace, /\/print`"\s*\n?\s*target="_blank"/);
    assert.doesNotMatch(workspace, /receipts\/\$\{receipt\.publicId\}\/print`/);
    assert.equal((workspace.match(/<IssuePrintButton/g) ?? []).length, 4);
    assert.ok(workspace.includes('`${invoiceBase}/issue`'));
    assert.ok(workspace.includes('<IssuedCopies'));
    assert.ok(invoicePage.includes("url.replace(/\\/print$/, '/issue')"));
});

test('a resent request is safe, the button shows it is busy, and old versions reprint only after a confirm', () => {
    assert.ok(button.includes('idempotency_key: crypto.randomUUID()'));
    assert.ok(button.includes("'Issuing…'"));
    assert.ok(button.includes('Nothing was printed or recorded.'));
    assert.ok(
        copies.includes(
            'copy.latest ? reprint(copy) : (confirming.value = copy)',
        ),
    );
    assert.ok(copies.includes('OperationalConfirmDialog'));
    assert.ok(copies.includes('No copies issued yet.'));
});
