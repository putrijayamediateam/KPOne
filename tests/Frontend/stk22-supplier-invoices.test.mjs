import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/SupplierInvoices.vue');

test('the Inventory task row links to Supplier invoices', () => {
    assert.match(
        read('resources/js/pages/Inventory/Index.vue'),
        /href="\/inventory\/supplier-invoices"[\s\S]*?>Supplier invoices<\/ActionLink/,
    );
});

test('amounts appear only when the server sends them to a cost holder', () => {
    assert.match(page, /v-if="invoices\.showAmounts"/);
    assert.match(
        page,
        /Invoice amounts are shown only to a Director or Finance Officer\./,
    );
    assert.match(page, /KPOne pays nothing/);
    // Without cost, a line shows the quantity check only.
    assert.match(page, /'Quantity matches'/);
    assert.match(page, /v-if="line\.result"/);
});

test('the recorder is never offered Accept or Reject', () => {
    assert.match(page, /<template v-if="!invoice\.recordedByYou">/);
    assert.match(
        page,
        /You recorded this invoice, so a different Finance\s+Officer or Director must decide it\./,
    );
});

test('the decision dialog asks for a fixed reason and a note for Other', () => {
    for (const label of [
        'Price agreed with supplier',
        'Credit note expected',
        'Wrong quantity',
        'Not our order',
    ]) {
        assert.ok(page.includes(label), label);
    }

    assert.match(page, /'Add a note to explain why\.'/);
    assert.match(page, /id="decision-note-error"\s+role="alert"/);
    assert.match(page, /\?\.focus\(\)/);
    assert.match(page, /min-h-11 items-center gap-2 text-sm lg:min-h-9/);
});

test('each line is entered, with 0 for a line not on the invoice', () => {
    assert.match(page, /Use 0 for a line that\s+is not on it\./);
    assert.match(page, /idempotency_key: form\.key/);
    // The recorder copies the quantity from the paper invoice; nothing is pre-filled.
    assert.match(page, /\{ quantity: '', total: '' \}/);
    assert.match(
        page,
        /:aria-describedby="`invoice-line-\$\{index\}-quantity-error`"/,
    );
    assert.match(page, /Checked against the sum of the lines\./);
});

test('design round 2: our error pattern, no browser bubbles, money that never wraps', () => {
    assert.match(page, /data-testid="record-invoice"\s+novalidate/);
    assert.match(page, /'Enter the quantity\.'/);
    assert.match(page, /'Enter the line total\.'/);
    assert.match(
        page,
        /\[data-testid="record-invoice"\] \[aria-invalid="true"\]/,
    );
    assert.match(page, /:disabled="busy"\s*>Record invoice/);
    assert.match(page, /`RM\\u00a0\$\{rm\(sen\)\}`/);
    assert.doesNotMatch(page, /RM \{\{/);
    assert.match(page, /'higher' : 'lower'/);
    assert.match(
        page,
        /Amounts you enter are visible to a Director or Finance\s+Officer only\./,
    );
    assert.match(page, /Pick a reason to continue\./);
    assert.match(page, /This cannot be undone\./);
});
