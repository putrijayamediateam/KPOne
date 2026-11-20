// Classic only, CO-5: the Classic half of bv2-3-invoice-v2.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Billing/Invoice.vue');

test('Classic invoice page takes the invoice prop', () => {
    assert.match(
        classic,
        /defineProps<\{ invoice: CompletedInvoicePage \}>\(\)/,
    );
});

test('Classic invoice page keeps its summary rows and words', () => {
    for (const words of [
        'Subtotal',
        "'Discount'",
        "'Surcharge'",
        'Refund credit',
        'Total',
        "'Self-pay received'",
        "'Panel responsibility'",
        "'Pay later still owed'",
        'Amount due now',
        'No payments recorded.',
        'Totals only for your access.',
        'Billing details are not shown for your role.',
        'No invoice was issued for this visit.',
        'No billed items in this category.',
        'No printable documents for your access.',
        'Panel patient: no invoice is printed. A receipt prints only for cash the patient paid.',
    ]) {
        assert.ok(classic.replace(/\s+/g, ' ').includes(words), words);
    }
});
