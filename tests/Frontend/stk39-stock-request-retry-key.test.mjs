import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('STK-39: every form that creates a stock request sends a retry key and renews it after a save', () => {
    for (const file of [
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
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

    const replenishment = read(
        'resources/js/pages/Inventory/BranchReplenishment.vue',
    );
    assert.match(replenishment, /retry_key: sendKey\.value,/);
    assert.match(
        replenishment,
        /sendKey\.value = crypto\.randomUUID\(\);\s*sendOpen\.value = true;/,
    );
});
