// Classic only, CO-5: the Classic half of bv2-1-patient-billing-v2.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/components/billing/BillingWorkspace.vue');

test('Classic billing workspace keeps its entry dialog calls and the payment disabled condition', () => {
    for (const [, classicCall] of [
        ['@confirm="payment"', '@click="payment"'],
        [
            '@confirm="propose(responsibilityKind)"',
            '@click="propose(\'panel\')"',
        ],
        [
            '@confirm="reversalReceipt && reverse(reversalReceipt)"',
            '@click="reversalReceipt && reverse(reversalReceipt)"',
        ],
    ]) {
        assert.ok(classic.includes(classicCall), `Classic has ${classicCall}`);
    }

    assert.match(classic, /:disabled="busy \|\| !form\.method"/);
});
