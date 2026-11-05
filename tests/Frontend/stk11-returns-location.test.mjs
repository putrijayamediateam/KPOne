import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Stock setup offers the patient returns location type with a help line', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
    );
    assert.match(
        panel,
        /value: 'dispensary_returns',\s+label: 'Patient Returns \(not for dispensing\)'/,
    );
    assert.match(panel, /v-if="locationForm\.type === 'dispensary_returns'"/);
    assert.match(
        panel,
        /Medicines patients bring back go here\.\s+Stock here is never\s+dispensed\.\s+It leaves only by Stock count, Write-off, or a\s+move to another returns location\./,
    );
    assert.match(
        panel,
        /<InputError :message="locationForm\.errors\.type" \/>/,
    );
    // Daniel: both selects have a visible label tied to them, and the help line describes the type select.
    assert.match(
        panel,
        /<label id="location-branch-label" for="location-branch"/,
    );
    assert.match(panel, /<label id="location-type-label" for="location-type"/);
    assert.match(panel, /labelledby="location-type-label"/);
    assert.match(panel, /'location-type-help'/);
    assert.match(panel, /id="location-type-help"/);
    assert.match(
        read('resources/js/components/ui/select/OperationalSelect.vue'),
        /:aria-describedby="describedby"/,
    );
    // The catalogue's quick location picker never offers the returns type.
    assert.doesNotMatch(
        read('resources/js/components/catalogue/LocationPicker.vue'),
        /dispensary_returns/,
    );
});

test('the movement filter offers Patient Return', () => {
    const index = read('resources/js/pages/Inventory/Index.vue');
    assert.match(
        index,
        /\{ value: 'dispensary_return', label: 'Patient Return' \}/,
    );
    // The server labels location types (InventoryDirectoryService::label), so the filter shows "Patient Returns".
});
