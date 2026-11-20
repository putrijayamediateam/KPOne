// Classic only, CO-6: the Classic half of v2-inventory-orders.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classicPanel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const classicReceiving = read(
    'resources/js/components/inventory/VerifiedReceivingForm.vue',
);
const classicReviews = read(
    'resources/js/components/inventory/ReceivingReviews.vue',
);

test('2a: Classic Inventory operations import no V2 code', () => {
    for (const source of [classicPanel, classicReceiving, classicReviews]) {
        assert.ok(!source.includes('inventory-v2'));
        assert.ok(!source.includes('components/v2/'));
    }
});

test('2a: the Classic panel leaves out suppliers and purchase orders only when the V2 page asks', () => {
    assert.match(classicPanel, /hideOrders\?: boolean;/);
    assert.match(
        classicPanel,
        /v-if="operations\.permissions\.manageSuppliers && !hideOrders"/,
    );
    assert.match(classicPanel, /v-if="!hideOrders"/);
    assert.ok(!/withDefaults[\s\S]*hideOrders/.test(classicPanel));
    assert.ok(
        !read('resources/js/pages/Inventory/Index.vue').includes('hide-orders'),
    );
});
