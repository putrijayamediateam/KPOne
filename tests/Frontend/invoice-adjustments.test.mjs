import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dialog = read(
    'resources/js/components/billing/InvoiceAdjustmentDialog.vue',
);
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');
const completed = read('resources/js/pages/Billing/Invoice.vue');

test('adjustment dialog and threshold editor compile', () => {
    for (const path of [
        'resources/js/components/billing/InvoiceAdjustmentDialog.vue',
        'resources/js/components/billing/InvoiceAdjustmentThreshold.vue',
    ]) {
        const { descriptor } = parse(read(path), { filename: path });
        assert.deepEqual(
            compileTemplate({
                source: descriptor.template.content,
                filename: path,
                id: path,
            }).errors,
            [],
        );
    }
});
test('actions use explicit server capabilities and above-limit confirmation, with a distinct private reason input', () => {
    assert.match(dialog, /controls\?\.add \|\| controls\?\.reverse/);
    assert.match(dialog, /v-if="controls.add"/);
    assert.match(dialog, /v-if="controls.reverse"/);
    assert.match(dialog, /v-model="form.confirmed"\s+type="checkbox"/);
    assert.match(dialog, /confirm_above_limit: form.confirmed/);
    assert.match(dialog, /idempotency_key: key.value/);
    assert.match(
        dialog,
        /threshold_version: controls.value\?\.threshold.lockVersion/,
    );
    assert.match(dialog, /Not configured \(RM 0.00 for authority checks\)/);
    assert.match(dialog, /maxlength="500"/);
    assert.doesNotMatch(dialog, /taxable|delete|photo|QR upload/i);
});
test('completed invoice stays read-only and both financial presentations distinguish original subtotal from payable', () => {
    assert.match(workspace, /InvoiceAdjustmentDialog\s+v-if="!manage"/);
    assert.match(workspace, /adjustment.subtotalSen/);
    assert.match(workspace, /adjustment.adjustmentSen/);
    assert.match(completed, /adjustment.subtotalSen/);
    assert.match(completed, /adjustment.adjustmentSen/);
    assert.doesNotMatch(
        completed,
        /InvoiceAdjustmentDialog|Add Adjustment|reason|router.post/,
    );
});

test('mobile dialog retains its title while the form scrolls', () => {
    assert.match(dialog, /flex max-h-\[90dvh\] flex-col overflow-hidden/);
    assert.match(dialog, /DialogHeader class="shrink-0"/);
    assert.match(dialog, /min-h-0 space-y-4 overflow-y-auto/);
});
