import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
test('reporting components compile and label numeric gross/net reconciliation', () => {
    for (const p of [
        'resources/js/components/insights/SalesReconciliation.vue',
        'resources/js/pages/Billing/Print.vue',
        'resources/js/pages/PanelClaims/Show.vue',
    ]) {
        const { descriptor } = parse(read(p), { filename: p });
        assert.deepEqual(
            compileTemplate({
                source: descriptor.template.content,
                filename: p,
                id: p,
            }).errors,
            [],
        );
    }

    const reconciliation = read(
        'resources/js/components/insights/SalesReconciliation.vue',
    );

    for (const label of [
        'Gross item sales',
        'Invoice surcharges',
        'Invoice discounts',
        'Net invoice sales',
    ]) {
        assert.ok(reconciliation.includes(label));
    }

    for (const page of ['Today', 'Report']) {
        const body = read(`resources/js/pages/Insights/${page}.vue`);
        assert.match(body, /SalesReconciliation/);
        assert.match(body, /gross item sales/);
        assert.match(body, /Net invoice sales/);
    }
});
test('print amount breakdown belongs to invoice branch, receipt remains actual recorded amount', () => {
    const raw = read('resources/js/pages/Billing/Print.vue');
    assert.match(raw, /myr\(receipt.amountSen\)/);
    assert.match(raw, /Original subtotal/);
    assert.match(raw, /Adjusted total/);
    assert.match(raw, /print:bg-white print:text-black/);
    assert.match(raw, /adjustment.adjustmentSen/);
    assert.doesNotMatch(raw, /reason|payload_hash|idempotency_key/);
});
test('claim distinguishes saved invoice amounts from the claim-only adjustment', () => {
    const raw = read('resources/js/pages/PanelClaims/Show.vue');

    for (const key of [
        'invoiceSubtotalSen',
        'invoiceAdjustmentSen',
        'invoiceTotalSen',
        'claimOnlyAdjustmentSen',
    ]) {
        assert.ok(raw.includes(key));
    }

    assert.match(raw, /Original subtotal \(snapshot\)/);
    assert.match(raw, /Claim-only adjustment/);
    assert.equal((raw.match(/class="min-w-0 space-y-6"/g) ?? []).length, 2);
});
