import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-7 (owner decision 2026-11-06, "go with CAT-7, hide the price tier tab"): SET-01 Price tier is skipped, so the
// settings menu on Medicine details no longer shows a greyed "Price tier" item.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read(
    'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
);
const nav = page.slice(
    page.indexOf('<nav'),
    page.indexOf('</nav>', page.indexOf('<nav')),
);

test('the Medicine details settings menu has no Price tier item and keeps its two real pages', () => {
    assert.doesNotMatch(nav, /Price tier/);
    assert.doesNotMatch(nav, /aria-disabled/);
    assert.match(nav, /aria-current="page"[\s\S]*Medicine details/);
    assert.match(nav, /href="\/inventory-services-settings\/suppliers"/);
});

const suppliers = read(
    'resources/js/pages/InventoryServicesSettings/Suppliers.vue',
);
const supplierNav = suppliers.slice(
    suppliers.indexOf('<nav'),
    suppliers.indexOf('</nav>', suppliers.indexOf('<nav')),
);

test('the Suppliers settings menu matches: no Price tier item', () => {
    assert.doesNotMatch(supplierNav, /Price tier/);
    assert.doesNotMatch(supplierNav, /aria-disabled/);
    assert.match(
        supplierNav,
        /href="\/inventory-services-settings\/medicine-details"/,
    );
    assert.match(supplierNav, /aria-current="page"[\s\S]*Suppliers/);
});
