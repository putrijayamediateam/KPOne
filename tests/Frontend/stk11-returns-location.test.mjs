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
        /value: 'dispensary_returns',\s+label: 'Patient returns \(not for dispensing\)'/,
    );
    assert.match(panel, /v-if="locationForm\.type === 'dispensary_returns'"/);
    assert.match(
        panel,
        /Medicines patients bring back go here\. Stock here is never\s+dispensed\. It leaves only by Write-off or Transfer\./,
    );
    assert.match(
        panel,
        /<InputError :message="locationForm\.errors\.type" \/>/,
    );
    // The catalogue's quick location picker never offers the returns type.
    assert.doesNotMatch(
        read('resources/js/components/catalogue/LocationPicker.vue'),
        /dispensary_returns/,
    );
});

test('the movement filter and location list use plain words for returns', () => {
    const index = read('resources/js/pages/Inventory/Index.vue');
    assert.match(
        index,
        /\{ value: 'dispensary_return', label: 'Patient Return' \}/,
    );
    assert.match(index, /dispensary_returns: 'Patient returns'/);
    assert.match(index, /locationTypeLabels\[item\.type\] \?\? item\.type/);
    assert.doesNotMatch(index, /\$\{item\.name\} · \$\{item\.type\}`/);
});
