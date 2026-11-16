import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/V2/StockValue.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('Stock value: From and To are required, so neither date shows its own Clear beside Show (Daniel, 16 Nov)', () => {
    for (const label of ['From', 'To']) {
        assert.match(
            page,
            new RegExp(
                `<V2DateField v-model="${label.toLowerCase()}" label="${label}" subject="${label}" required`,
            ),
            label,
        );
    }

    // Show still needs both dates.
    assert.match(page, /from\.value !== '' && to\.value !== ''/);
});
