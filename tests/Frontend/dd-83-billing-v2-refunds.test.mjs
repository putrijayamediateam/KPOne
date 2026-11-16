import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-83 (Billing part): V2 Billing uses its own refunds block built on the V2
// kit; the Classic ReceiptRefunds.vue stays for the Classic workspace.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/components/billing/v2/ReceiptRefundsV2.vue');
const flat = v2.replace(/\s+/g, ' ');
const show = read('resources/js/pages/Billing/V2/Show.vue');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');

test('DD-83: V2 Billing uses ReceiptRefundsV2; Classic keeps ReceiptRefunds', () => {
    assert.match(
        show,
        /import ReceiptRefundsV2 from '@\/components\/billing\/v2\/ReceiptRefundsV2\.vue';/,
    );
    assert.match(show, /<ReceiptRefundsV2\s+v-if="billing\.refunds"/);
    assert.doesNotMatch(show, /<ReceiptRefunds\b/);
    assert.match(workspace, /<ReceiptRefunds\s+v-if="billing\.refunds"/);
});

test('DD-83: V2 kit only, no native select or input, same routes and fields as Classic', () => {
    assert.doesNotMatch(v2, /<select\b|<input\b/);
    assert.match(v2, /<V2MoneyInput/);
    assert.equal([...v2.matchAll(/<V2Picker\b/g)].length, 3);
    assert.match(v2, /`\$\{props\.base\}\/refunds\/\$\{receipt\.publicId\}`/);
    assert.match(v2, /`\$\{props\.base\}\/refunds\/\$\{refund\}\/approved`/);
    assert.match(
        v2,
        /`\$\{props\.base\}\/refunds\/\$\{target\.refund\}\/rejected`/,
    );

    for (const field of [
        'payment_method_id',
        'reason_code',
        'amount_sen',
        'idempotency_key',
        'expected_branch_id',
        'return_to',
    ]) {
        assert.ok(v2.includes(field), field);
    }
});

test('DD-83: a reversed receipt shows the full amount in words; the action stays off until both picks', () => {
    assert.match(flat, /Full amount \{\{ myr\(receipt\.amountSen\) \}\}/);
    assert.match(v2, /return `Pick \$\{list\} to continue\.`;/);
    assert.match(
        flat,
        /:disabled=" busy\[receipt\.publicId\] \|\| missing\(receipt\) !== null "/,
    );
});

test('DD-83: Record refund outline, state chip with a dot, red only in the Reject confirm, 44 px below lg', () => {
    assert.match(flat, /<Button type="submit" variant="outline"/);
    assert.match(
        flat,
        /<V2Chip :tone="stateTone\[refund\.state\]" :label="stateText\[refund\.state\]" \/>/,
    );
    assert.doesNotMatch(v2, /StatusBadge/);
    assert.match(
        flat,
        /variant="outline" class="max-lg:min-h-11" :aria-label="`Reject refund of/,
    );
    assert.match(flat, /confirm-label="Reject refund" tone="danger"/);
    assert.doesNotMatch(v2, /variant="destructive"/);
    assert.ok([...v2.matchAll(/max-lg:min-h-11/g)].length >= 3);
});
