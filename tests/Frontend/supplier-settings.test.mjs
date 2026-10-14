import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/InventoryServicesSettings/Suppliers.vue');

test('Suppliers preserves the visible Yezza settings layout and multi-contact seller dialog', () => {
    for (const text of ['Inventory and services settings', 'Clinic management', 'Price tier', 'Medicine details', 'Suppliers', 'NAME', 'PIC', 'PHONE', 'STATUS', 'ACTION', 'Add new seller', 'Sold by', 'Company name', 'PIC name', 'Phone number', '+ Add more', 'Cancel', 'Save']) {
        assert.ok(page.includes(text), `Missing ${text}`);
    }

    assert.match(page, /role="switch"/);
    assert.match(page, /aria-current="page"/);
    assert.match(page, /v-for="\(contact, index\) in form\.contacts"/);
    assert.match(page, /country: 'MY'/);
    assert.match(page, /More actions are not available yet/);
    assert.doesNotMatch(page, /Delete supplier|Remove supplier|localStorage|sessionStorage/);
});

test('Supplier actions send branch, version and retry keys with field errors and bounded navigation', () => {
    assert.match(page, /lock_version: supplier\.lockVersion/);
    assert.match(page, /expected_branch_id: props\.branchId/);
    assert.match(page, /idempotency_key: crypto\.randomUUID\(\)/);
    assert.match(page, /statusKeys\.get\(token\)/);
    assert.match(page, /form\.errors\.name/);
    assert.match(page, /contactError\(index, 'phone'\)/);
    assert.match(page, /CompactPagination/);
    assert.match(read('routes/web.php'), /permission:catalogue\.settings\.manage\.organisation/);
    assert.match(read('app/Http/Controllers/SupplierSettingsController.php'), /redirectTo\(route\('supplier-settings\.index'\)\)/);
    assert.match(read('resources/js/lib/workspace-navigation.ts'), /href: '\/inventory-services-settings\/suppliers'/);
});
