import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Medicine details settings: look only. Same props, routes, payloads and lock versions as Classic.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
);
const v2 = read(
    'resources/js/pages/InventoryServicesSettings/V2/MedicineDetails.vue',
);
const registry = read('config/v2-pages.php');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('InventoryServicesSettings/MedicineDetails is registered and the V2 page uses the V2 blocks', () => {
    assert.match(registry, /'InventoryServicesSettings\/MedicineDetails'/);

    for (const block of [
        '<V2WorkLayout',
        '<PageFrame',
        '<V2Table',
        '<V2Tabs',
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
        "'/inventory-services-settings/medicine-details'",
        '`/inventory-services-settings/medicine-details/${dialogType.value}`',
        '`/inventory-services-settings/medicine-details/${dialogType.value}/${selected.value.id}/rename`',
        '`/inventory-services-settings/medicine-details/${props.type}/${option.id}/active`',
        'expected_branch_id: props.branchId',
        'lock_version: option.lockVersion',
        'active: !option.active',
        'idempotency_key: key',
        'labels: [',
        "['precaution', 'indication'].includes(props.type)",
        'addForm.labels.length >= 20',
        'maxlength="200"',
    ]) {
        assert.ok(squash(classic).includes(squash(key)), `classic ${key}`);
        assert.ok(squash(v2).includes(squash(key)), `v2 ${key}`);
    }
});

test('V2 asks before archiving, restores directly, and has no native select or inner side menu', () => {
    assert.match(v2, /archiving\.value = option/);
    assert.ok(
        v2.includes('label: "Restore"') || v2.includes("label: 'Restore'"),
    );
    assert.ok(!/<select\b/.test(v2));
    assert.ok(!v2.includes('Suppliers'));
    assert.ok(v2.includes("title: 'Clinic management'"));
});

test('Classic imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});
