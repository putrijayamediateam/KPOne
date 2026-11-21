// Classic only, CO-8: the Classic half of v2-suppliers, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/InventoryServicesSettings/Suppliers.vue',
);
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('Classic Suppliers calls its routes with the payloads and lock versions', () => {
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
    }
});
