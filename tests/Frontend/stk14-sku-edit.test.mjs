import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/Skus.vue');

test('locked unit fields are shown read-only with the reason and never sent', () => {
    assert.match(page, /These cannot change because this SKU already has/);
    assert.match(page, /:disabled="sku\.unitsLocked"/);
    assert.match(
        page,
        /if \(sku\.unitsLocked\) \{[\s\S]{0,400}delete sent\[field\]/,
    );

    for (const field of [
        'sku_code',
        'stock_unit',
        'unit_conversion',
        'pack_size',
    ]) {
        assert.match(page, new RegExp(`'${field}'`));
    }
});

test('every save carries the versions the screen showed', () => {
    assert.match(page, /sku_lock_version: sku\.lockVersion/);
    assert.match(page, /item_lock_version: sku\.item\.lockVersion/);
    assert.match(page, /Legacy pack fields/);
    assert.match(page, /v-if="skus\.canManage && editing !== sku\.publicId"/);
});

test('a storage value saved before this screen stays selectable', () => {
    assert.match(page, /storageOptions\(\s*sku\.storageType,?\s*\)/);
});
