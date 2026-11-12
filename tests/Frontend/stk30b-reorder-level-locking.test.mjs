import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const panel = readFileSync(
    new URL(
        '../../resources/js/components/inventory/InventoryOperationsPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('STK-30b: the reorder form loads the saved level and sends its version and a key', () => {
    assert.match(panel, /\/inventory\/reorder-levels\/current\?/);
    assert.match(
        panel,
        /watch\(\s*\(\) => \[reorder\.location, reorder\.sku\],\s*loadReorderLevel/,
    );
    assert.match(panel, /expected_lock_version: reorder\.lockVersion,/);
    assert.match(panel, /idempotency_key: reorder\.key,/);
    // A late answer for an earlier choice never overwrites the form.
    assert.match(panel, /if \(lookup !== reorderLookup\) \{\s*return;/);
    assert.match(
        panel,
        /:disabled="\s*busy \|\|\s*reorder\.loading \|\|\s*reorder\.loadFailed\s*"/,
    );
    // A new pair never keeps the previous pair's numbers, and a stale save shows the saved level again.
    assert.match(
        panel,
        /reorder\.level = '';\s*reorder\.max = '';\s*reorder\.loadFailed = false;/,
    );
    assert.match(
        panel,
        /if \(errors\.expected_lock_version\) \{[\s\S]*?loadReorderLevel\(\)/,
    );
});
