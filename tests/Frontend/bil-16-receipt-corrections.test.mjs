import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// BIL-16 (owner decisions 2026-11-10): correct a receipt recorded in error on a completed visit, in one step.
const path = 'resources/js/components/billing/ReceiptCorrections.vue';
const raw = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = raw.replace(/\s+/g, ' ');
const workspace = readFileSync(
    new URL(
        '../../resources/js/components/billing/BillingWorkspace.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('the correction panel compiles and sits on the Billing page only when the server sends it', () => {
    const descriptor = parse(raw, { filename: path }).descriptor;
    const result = compileTemplate({
        id: 'test-corrections',
        filename: path,
        source: descriptor.template?.content ?? '',
    });
    assert.deepEqual(result.errors, []);
    assert.ok(workspace.includes('v-if="billing.corrections"'));
    assert.ok(workspace.includes(':base="invoiceBase"'));
});

test('it posts one correction with the versions, what was received and the recording-error confirmation', () => {
    assert.ok(
        page.includes('`${props.base}/payments/${receipt.publicId}/correct`'),
    );

    for (const field of [
        'lock_version: props.corrections.invoiceLockVersion',
        'payment_lock_version: receipt.lockVersion',
        'received_sen: sen',
        'recording_error_only:',
        'idempotency_key: f.key',
    ]) {
        assert.ok(page.includes(field), field);
    }

    assert.ok(page.includes('This is not a refund.'));
    assert.ok(page.includes('No money is being handed back.'));
});

test('it explains what becomes due, the pay-later floor and why a receipt cannot be corrected', () => {
    assert.ok(page.includes('will become due on this visit.'));
    assert.ok(page.includes('which cannot grow back, so enter at least that.'));
    assert.ok(page.includes('{{ receipt.refused }}'));
    assert.ok(page.includes('Number(sen) >= receipt.payLaterSen'));
});
