import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BV2-3: the Version 2 completed-visit Invoice. Same prop, routes and words as
// Classic; read only; the shared Billing pieces are reused, not copied.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Billing/V2/Invoice.vue');
const paymentsCard = read(
    'resources/js/components/billing/v2/InvoicePaymentsCardV2.vue',
);
const flat = `${page} ${paymentsCard}`.replace(/\s+/g, ' ');
const classic = read('resources/js/pages/Billing/Invoice.vue');

test('BV2-3: the page is registered and takes the same invoice prop as Classic', () => {
    assert.match(read('config/v2-pages.php'), /'Billing\/Invoice',/);
    assert.match(page, /defineProps<\{ invoice: CompletedInvoicePage \}>\(\)/);
    assert.match(
        classic,
        /defineProps<\{ invoice: CompletedInvoicePage \}>\(\)/,
    );
});

test('BV2-3: read only, nothing is sent or calculated on the page', () => {
    for (const source of [page, paymentsCard]) {
        assert.doesNotMatch(source, /router\.|useForm|fetch\(|axios/);
        assert.doesNotMatch(source, /<Button\b/);
    }

    for (const amount of [
        'myr(line.totalSen)',
        'myr(line.unitPriceSen)',
        'myr(invoice.financial.state.total)',
        'myr(invoice.financial.state.due_now)',
        'myr(payment.amountSen)',
        'myr(invoice.financial.refundCreditSen)',
    ]) {
        assert.ok(flat.includes(amount), amount);
    }
});

test('BV2-3: the same summary rows and words as Classic', () => {
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
        assert.ok(flat.includes(words), words);
        assert.ok(classic.replace(/\s+/g, ' ').includes(words), words);
    }
});

test('BV2-3: shared Billing pieces and recorded prints are reused', () => {
    for (const piece of [
        '<InvoiceActivityDrawer :context="invoice.links.activity" :notes-context="invoice.notes" />',
        '<InvoiceBillTo :context="invoice.billTo" />',
        '<InvoiceNotes :context="invoice.notes" />',
        '<OutstandingBills :groups="invoice.outstanding" />',
    ]) {
        assert.ok(flat.includes(piece), piece);
    }

    assert.match(page, /url\.replace\(\/\\\/print\$\/, '\/issue'\)/);
    assert.equal([...page.matchAll(/return-to="invoice"/g)].length, 2);
});

test('BV2-3: name and visit head the page; summary in the rail from 1280 px, above below it', () => {
    assert.match(flat, /:title="invoice\.patient\.name"/);
    assert.match(flat, /invoice\.visit\.visitNumber/);
    assert.match(flat, /rail-from="xl" rail-below="stack-top"/);
    assert.match(flat, /<template #rail>/);
});

test('BV2-3: one payments card, in the rail from 1280 px and under the items below it', () => {
    const pageFlat = page.replace(/\s+/g, ' ');
    assert.match(
        pageFlat,
        /<InvoicePaymentsCardV2 v-if="invoice\.financial" :invoice="invoice" class="max-xl:hidden" \/>/,
    );
    assert.match(
        pageFlat,
        /<InvoicePaymentsCardV2 v-if="invoice\.financial" :invoice="invoice" class="xl:hidden" \/>/,
    );
});

test('BV2-3: receipt status is a V2 chip, a reversed receipt stays grey', () => {
    assert.match(paymentsCard, /status === 'reversed' \? 'neutral'/);
    assert.match(flat, /<V2Chip v-bind="receiptChip\(payment\.status\)" \/>/);
    assert.match(flat, /<V2Tabs v-model="tab"/);
});

test('BV2-3: 44 px controls below 1024 px under a page-only body marker', () => {
    assert.match(
        page,
        /onMounted\(\(\) => document\.body\.setAttribute\('data-invoice-v2', ''\)\)/,
    );
    assert.match(
        page,
        /onUnmounted\(\(\) =>\s*document\.body\.removeAttribute\('data-invoice-v2'\),?\s*\)/,
    );
    assert.match(page, /max-width: 1023\.98px/);
    assert.match(page, /min-height: 44px/);
    assert.match(page, /h-11 [^']*lg:h-9/);
});
