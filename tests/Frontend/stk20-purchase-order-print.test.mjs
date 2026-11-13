import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the purchase order print is a plain A4 page with no shell', () => {
    assert.match(
        read('resources/js/app.ts'),
        /case name === 'Inventory\/PurchaseOrderPrint':/,
    );
    const page = read('resources/js/pages/Inventory/PurchaseOrderPrint.vue');
    assert.match(page, /PURCHASE ORDER/);
    assert.match(page, /'NOT APPROVED, NOT AN ORDER'/);
    assert.match(page, /'CANCELLED, NOT AN ORDER'/);
    assert.match(page, /Prices as agreed with the supplier\./);
    assert.match(page, /v-if="order\.showPrices"/);
    assert.match(page, /size: A4;/);
    assert.match(page, /Not all lines have a price/);
});

test('only an approved order looks like an order (design review)', () => {
    const page = read('resources/js/pages/Inventory/PurchaseOrderPrint.vue');
    assert.match(page, /submitted: 'WAITING FOR APPROVAL, NOT AN ORDER'/);
    // The supplier reads "Approved" and the date, never internal receiving words.
    assert.match(page, /`Approved\$\{/);
    assert.doesNotMatch(page, /Fully received|Partially received/);
    // No signature line on a sheet that is not an order.
    assert.match(page, /<div v-if="order\.isOrder">[\s\S]*?po-signature/);
    // 15 mm margins, page numbers, and the totals kept with the last rows.
    assert.match(page, /margin: 15mm;/);
    assert.match(page, /'Page ' counter\(page\) ' of ' counter\(pages\)/);
    assert.match(
        page,
        /\.po-end \{\s*break-inside: avoid;\s*break-before: avoid;/,
    );
    // On a narrow screen a cost holder sees the price under the item.
    assert.match(page, /class="po-narrow-price text-xs"/);
});

test('money prints with a thousands separator and the banner repeats on every page', () => {
    const page = read('resources/js/pages/Inventory/PurchaseOrderPrint.vue');
    assert.ok(page.includes('/\\B(?=(\\d{3})+(?!\\d))/g'));
    assert.ok(page.includes("','"));
    assert.doesNotMatch(page, /formatRinggit/);
    assert.match(
        page,
        /:style="printPage \? \{ page: printPage \} : undefined"/,
    );

    for (const [name, text] of [
        ['po-not-approved', 'NOT APPROVED, NOT AN ORDER'],
        ['po-waiting', 'WAITING FOR APPROVAL, NOT AN ORDER'],
        ['po-cancelled', 'CANCELLED, NOT AN ORDER'],
    ]) {
        assert.match(
            page,
            new RegExp(
                `@page ${name} \\{\\s*@top-center \\{\\s*content: '${text}';`,
            ),
        );
    }
});

test('the Inventory panel links each order to its print', () => {
    assert.match(
        read('resources/js/components/inventory/InventoryOperationsPanel.vue'),
        /:href="`\/inventory\/purchase-orders\/\$\{row\.publicId\}\/print`"/,
    );
});
