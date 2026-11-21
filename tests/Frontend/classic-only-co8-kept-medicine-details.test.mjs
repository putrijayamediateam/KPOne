// Classic only, CO-8: the Classic half of v2-medicine-details, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
);
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('Classic Medicine details calls its routes with the payloads and lock versions', () => {
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
    }
});
