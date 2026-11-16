import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('STK-39: every form that creates a stock request sends a retry key and renews it after a save', () => {
    for (const file of [
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
        'resources/js/composables/inventory-v2/useRequestsAndTransfers.ts',
    ]) {
        const source = read(file);
        assert.match(source, /key: crypto\.randomUUID\(\),/, file);
        assert.match(source, /retry_key: stockRequest\.key,/, file);
        assert.match(
            source,
            /stockRequest\.key = crypto\.randomUUID\(\);/,
            file,
        );
    }

    const section = read(
        'resources/js/components/inventory-v2/RequestsSection.vue',
    );
    assert.match(section, /retry_key: stockRequest\.key,/);
    assert.match(section, /stockRequest\.key = crypto\.randomUUID\(\);/);

    const replenishment = read(
        'resources/js/pages/Inventory/BranchReplenishment.vue',
    );
    assert.match(replenishment, /retry_key: sendKey\.value,/);
    assert.match(
        replenishment,
        /sendKey\.value = crypto\.randomUUID\(\);\s*sendOpen\.value = true;/,
    );
});
