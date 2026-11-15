import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('expiring batches expose visible location context for duplicate SKU and batch rows', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );

    assert.match(
        panel,
        /:key="`\$\{row\.sku\}-\$\{row\.batch\}-\$\{row\.location\}`"/,
    );
    assert.match(panel, /Location:\s*\{\{ row\.location \}\}/);
    assert.match(panel, /\{\{ row\.quantity \}\}[\s\S]*in\s+stock/);
    assert.match(panel, /break-words/);
    assert.match(panel, /whitespace-normal/);
});

test('movement filters retain accessible inclusive date controls and query state', () => {
    const page = read('resources/js/pages/Inventory/Index.vue');
    const types = read('resources/js/types/inventory-operations.ts');

    for (const [id, model] of [
        ['movement-date-from', 'dateFrom'],
        ['movement-date-to', 'dateTo'],
    ]) {
        assert.match(page, new RegExp(`<label for="${id}"`));
        assert.match(page, new RegExp(`id="${id}"[\\s\\S]*v-model="${model}"`));
    }

    assert.match(page, /date_from:[\s\S]*dateFrom\.value \|\| undefined/);
    assert.match(page, /date_to:[\s\S]*dateTo\.value \|\| undefined/);
    assert.match(page, /dateFrom\.value = ''/);
    assert.match(page, /dateTo\.value = ''/);
    assert.match(page, /@change="\(page\) => visit\(\{ page \}\)"/);
    assert.match(
        page,
        /No \$\{inventory\.tab === 'movements' \? 'movement' : 'stock'\} records/,
    );
    assert.match(types, /'date_from'/);
    assert.match(types, /'date_to'/);
});
