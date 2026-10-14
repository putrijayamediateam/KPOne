import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
    'utf8',
);
const service = readFileSync(
    'app/Domain/Clinical/Services/MedicineOptionSettingsService.php',
    'utf8',
);
const controller = readFileSync(
    'app/Http/Controllers/MedicineOptionSettingsController.php',
    'utf8',
);

test('Medicine details retains the seven screenshot tabs and approved archive-only menus', () => {
    for (const label of [
        'Drug group',
        'Instruction',
        'Precaution',
        'Frequency',
        'Duration',
        'Dosage unit',
        'Indication',
    ]) {
        assert.ok(service.includes(label));
    }

    for (const label of [
        'Medicine details',
        'Add new',
        'Rename',
        'Archive',
        'Confirm',
        'Cancel',
        'Save',
        'NAME',
        'STATUS',
        'ACTIONS',
    ]) {
        assert.ok(page.includes(label));
    }

    assert.match(page, /\['precaution', 'indication'\]\.includes/);
    assert.match(page, /Add more/);
    assert.match(
        page.replace(/\s+/g, ' '),
        /Saved medicines and prescriptions keep their text/,
    );
    assert.doesNotMatch(page, /Delete|localStorage|sessionStorage/);
});

test('Medicine details sends branch versions and stable retry keys and renders validation errors', () => {
    for (const field of [
        'expected_branch_id',
        'lock_version',
        'idempotency_key',
        'crypto.randomUUID()',
        'statusKeys',
        'labels.',
        'InputError',
    ]) {
        assert.ok(page.includes(field));
    }

    assert.match(page, /@change="go"/);
    assert.match(controller, /to_route\('medicine-details.index'/);
    assert.match(controller, /redirectTo\(route\('medicine-details.index'/);
    assert.doesNotMatch(controller, /\bback\(/);
    assert.match(service, /catalogue.settings.manage.organisation/);
});
