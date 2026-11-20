// Classic only, CO-6: the Classic half of v2-inventory-requests.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classicPanel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);
const classicReceipt = read(
    'resources/js/components/inventory/TransferReceiptForm.vue',
);
const classicDifferences = read(
    'resources/js/components/inventory/TransferDifferences.vue',
);

test('2b: Classic Inventory operations keep their requests section', () => {
    for (const source of [classicPanel, classicReceipt, classicDifferences]) {
        assert.ok(!source.includes('inventory-v2'));
        assert.ok(!source.includes('components/v2/'));
    }

    assert.match(classicPanel, /hideRequests\?: boolean;/);
    assert.match(classicPanel, /v-if="!hideRequests"/);
    assert.ok(!/withDefaults[\s\S]*hideRequests/.test(classicPanel));
    assert.ok(
        !read('resources/js/pages/Inventory/Index.vue').includes(
            'hide-requests',
        ),
    );
});

test('2b: Classic quantity words and the picker location parameter', () => {
    const words = read('resources/js/lib/inventory-words.ts');
    assert.match(words, /trimQuantity\(String\(value \?\? '0'\)\)/);
    assert.match(
        read('resources/js/lib/inventory-picker.ts'),
        /params\.set\('location', input\.location\);/,
    );
});
