import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the stock view offers Patient returns only, and the server accepts it', () => {
    assert.match(
        read('resources/js/pages/Inventory/Index.vue'),
        /\{ value: 'returns', label: 'Patient returns only' \}/,
    );
    assert.match(
        read('app/Http/Controllers/InventoryController.php'),
        /'status' => \['nullable', Rule::in\(\['active', 'inactive', 'returns'\]\)\]/,
    );
});
