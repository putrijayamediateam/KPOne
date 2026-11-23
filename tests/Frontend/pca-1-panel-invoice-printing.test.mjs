import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (src) => src.replace(/\s+/g, ' ');
const classic = read('resources/js/components/billing/BillingWorkspace.vue');
const invoicePage = read('resources/js/pages/Billing/Invoice.vue');
const copies = read('resources/js/components/billing/IssuedCopies.vue');
const types = read('resources/js/types/billing.ts');
const printPage = read('resources/js/pages/Billing/Print.vue');
const history = read('resources/js/pages/Visits/History.vue');
const message =
    'Panel patient: no invoice is printed. A receipt prints only for cash the patient paid.';

test('PCA-1: Print invoice follows the server flag on Classic and Version 2, with the same note', () => {
    for (const [name, src] of [
        ['Classic', classic],
    ]) {
        assert.match(
            src,
            /v-if="\s*billing\.can\.print &&\s*billing\.can\.printInvoice\s*"/,
            name,
        );
        assert.match(src, /data-testid="panel-no-invoice-print"/, name);
        assert.ok(flat(src).includes(message), name);
    }

    assert.match(types, /'printInvoice'/);
});

test('PCA-1: the completed invoice page explains why there is no print', () => {
    assert.match(invoicePage, /invoice\.financial\?\.panelNoInvoicePrint/);
    assert.ok(flat(invoicePage).includes(message));
});

test('PCA-1: a view-only invoice copy offers View, never Reprint', () => {
    assert.equal(copies.match(/v-if="copy\.reprintable !== false"/g).length, 2);
    assert.equal(copies.match(/v-else-if="copy\.viewUrl"/g).length, 2);
    assert.match(copies, /:href="viewHref\(copy\)"/);
    assert.match(copies, /\$\{copy\.viewUrl\}\?from=/);
    assert.match(copies, /copy\.reprintable === false/);
    assert.ok(
        flat(copies).includes(
            'Earlier copies can be viewed. They cannot be reprinted.',
        ),
    );
    assert.match(types, /reprintable\?: boolean;/);
    assert.match(types, /viewUrl\?: string \| null;/);
});

test('PCA-1: the view page of an earlier Panel invoice copy offers no print and prints only a VIEW ONLY line', () => {
    assert.match(
        printPage,
        /v-if="!issued\?\.viewOnly"\s+class="rounded border px-3 py-2"\s+@click="print"/,
    );
    assert.match(printPage, /data-testid="view-only-note"/);
    assert.match(printPage, /hidden text-center text-lg font-bold print:block/);
    assert.match(
        printPage,
        /:class="\{ 'print:hidden': issued\?\.viewOnly \}"/,
    );
    assert.ok(flat(printPage).includes('VIEW ONLY — not an issued copy.'));
    assert.match(history, /history\.financial\?\.panelNoInvoicePrint/);
});
