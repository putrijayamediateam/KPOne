// Classic only, CO-5: the Classic half of dd-83-billing-v2-refunds.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');

test('Classic billing workspace keeps ReceiptRefunds', () => {
    assert.match(workspace, /<ReceiptRefunds\s+v-if="billing\.refunds"/);
});
