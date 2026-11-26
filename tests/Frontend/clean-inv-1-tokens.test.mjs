import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CLEAN-INV-1: the inventory screens use Clean Look tokens, never palette classes.
// Print pages keep a white page (PRINT-1); the camera viewfinder keeps black video.
const files = [
    'resources/js/pages/Inventory/GovernedStock.vue',
    'resources/js/pages/Inventory/Skus.vue',
    'resources/js/pages/Inventory/Readiness.vue',
    'resources/js/pages/Inventory/BatchHolds.vue',
    'resources/js/pages/Inventory/SupplierReturns.vue',
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
    'resources/js/components/inventory/TransferDifferences.vue',
    'resources/js/components/inventory/TransferReceiptForm.vue',
];
const palette =
    /\b(?:bg|text|border|ring)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/;

test('CLEAN-INV-1: no palette colour classes on inventory screens', () => {
    for (const file of files) {
        assert.doesNotMatch(readFileSync(file, 'utf8'), palette, file);
    }
});

test('CLEAN-INV-1: transfer difference status is a dot-plus-word StatusBadge', () => {
    const source = readFileSync(
        'resources/js/components/inventory/TransferDifferences.vue',
        'utf8',
    );
    assert.match(source, /<StatusBadge/);
    assert.match(
        source,
        /:tone="difference.status === 'open' \? 'warning' : 'success'"/,
    );
});
