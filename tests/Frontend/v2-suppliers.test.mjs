import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Suppliers settings: look only. Same props, routes, payloads and lock versions as Classic.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/InventoryServicesSettings/Suppliers.vue',
);
const v2 = read(
    'resources/js/pages/InventoryServicesSettings/V2/Suppliers.vue',
);
const registry = read('config/v2-pages.php');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('InventoryServicesSettings/Suppliers is registered and the V2 page uses the V2 blocks', () => {
    assert.match(registry, /'InventoryServicesSettings\/Suppliers'/);

    for (const block of [
        '<V2WorkLayout',
        '<PageFrame',
        '<V2Table',
        '<V2Toolbar',
        '<V2Pager',
        '<V2RowMenu',
        '<V2Dialog',
    ]) {
        assert.ok(v2.includes(block), block);
    }
});

test('V2 calls the same routes with the same payloads and lock versions', () => {
    for (const key of [
        "'/inventory-services-settings/suppliers'",
        "form.post('/inventory-services-settings/suppliers'",
        '`/inventory-services-settings/suppliers/${encodeURIComponent(supplier.publicId)}/active`',
        'expected_branch_id: props.branchId',
        'lock_version: supplier.lockVersion',
        'active: !supplier.active',
        'idempotency_key: key',
        "contacts: [{ name: '', phone: '', country: 'MY' }]",
        'form.contacts.length >= 20',
        'maxlength="200"',
        'maxlength="40"',
    ]) {
        assert.ok(squash(classic).includes(squash(key)), `classic ${key}`);
        assert.ok(squash(v2).includes(squash(key)), `v2 ${key}`);
    }
});

test('V2 asks before archiving, activates directly, and shows no native select or inner side menu', () => {
    assert.ok(v2.includes('Archive supplier'));
    assert.match(v2, /archiving\.value = supplier/);
    assert.ok(!/<select\b/.test(v2));
    assert.ok(!v2.includes('Medicine details'));
    assert.ok(v2.includes("title: 'Clinic management'"));
});

test('Classic imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});
