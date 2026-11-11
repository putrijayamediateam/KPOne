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

test('the Inventory panel links each order to its print', () => {
    assert.match(
        read('resources/js/components/inventory/InventoryOperationsPanel.vue'),
        /:href="`\/inventory\/purchase-orders\/\$\{row\.publicId\}\/print`"/,
    );
});
