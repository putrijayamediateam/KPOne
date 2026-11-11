import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/Skus.vue');

test('locked unit fields are shown read-only with the reason and never sent', () => {
    assert.match(page, /These cannot change because this SKU already has/);
    // Daniel's review: locked values read as label/value rows with a lock icon, not dimmed inputs.
    assert.match(page, /<template v-if="!sku\.unitsLocked">/);
    assert.match(page, /v-for="\[label, value\] in lockedRows\(sku\)"/);
    assert.doesNotMatch(page, /:disabled="sku\.unitsLocked"/);
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

test('read-only viewers, status help, stock confirmation and shared barcodes are explained', () => {
    assert.match(page, /You can view SKUs but not change them\./);
    assert.match(page, /Item active affects every SKU of this item\./);
    assert.match(
        page,
        /An inactive SKU cannot be ordered, received or\s+dispensed\./,
    );
    assert.match(
        page,
        /sku\.onHand && deactivates && confirming\.value === null/,
    );
    assert.match(page, /<OperationalConfirmDialog/);
    assert.match(
        page,
        /Barcode also on another SKU\. Fix it on Pilot\s+readiness\./,
    );
});
