import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
);
const section = read(
    'resources/js/components/inventory-v2/StockSetupSection.vue',
);
const home = read('resources/js/pages/Inventory/V2/Index.vue');

/** The source of `const name = …` up to the next top-level `const`, without whitespace (indentation differs). */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|let) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|watchEffect|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (
        (next < 0 ? rest : rest.slice(0, next + indent.length))
            .replace(/\s+/g, '')
            // Prettier adds a trailing comma when the same code sits one level deeper.
            .replace(/,(?=[)\]}])/g, '')
    );
};

test('2d: the six Classic forms, the batch loader and the location types are verbatim copies', () => {
    for (const name of [
        'ReferenceData',
        'SkuBatch',
        'skuBatches',
        'batchesLoading',
        'batchesError',
        'batchSearch',
        'batchesRequest',
        'loadSkuBatches',
        'openingBalanceForm',
        'openingBalanceError',
        'submitOpeningBalance',
        'itemForm',
        'submitItem',
        'skuForm',
        'submitSku',
        'locationForm',
        'locationTypeOptions',
        'submitLocation',
        'batchForm',
        'submitBatch',
        'mappingForm',
        'submitMapping',
    ]) {
        assert.equal(block(section, name), block(classic, name), name);
    }

    // The same routes as Classic.
    for (const route of [
        "'/inventory/opening-balances'",
        "'/inventory-references/items'",
        "'/inventory-references/skus'",
        "'/inventory-references/locations'",
        "'/inventory-references/batches'",
        "'/inventory-references/mappings'",
    ]) {
        assert.ok(section.includes(route), route);
    }
});

test("2d: six outline buttons, one filled action and a discard guard per sheet (Daniel's rules)", () => {
    const home2d = section.slice(
        section.indexOf('<template>'),
        section.indexOf('<V2Dialog'),
    );
    assert.equal(
        (home2d.match(/@click="open\(action\.id\)"/g) ?? []).length,
        1,
    );
    assert.ok(!/bg-primary/.test(home2d), 'no filled button on the bar');
    assert.equal((section.match(/<V2Dialog/g) ?? []).length, 6);
    assert.equal((section.match(/:dirty="\w+Form\.isDirty"/g) ?? []).length, 6);
    assert.equal((section.match(/@confirm="submit"/g) ?? []).length, 6);
    // A refused save keeps the sheet open: it closes only after a successful save.
    assert.match(section, /if \(done && sheet\.value === id\)/);
});

test('2d: the wording Daniel asked for', () => {
    // An opening balance counts at once; the sign-off is a check (STK-1, InventoryBatchEligibility).
    assert.match(
        section,
        /It counts at once; someone else checks it on Pilot readiness\./,
    );
    assert.ok(!/before it counts/.test(section));
    assert.match(section, /The type cannot be changed later\./);
    assert.match(section, /Stock here is never\s+dispensed\./);
    // Batch expiry: day first, no minimum, and a warning (not an error) for a past date.
    assert.match(section, /<V2DateField\s+v-model="batchForm\.expiry_date"/);
    assert.ok(!/batchForm\.expiry_date"[\s\S]{0,200}:min=/.test(section));
    assert.match(
        section,
        /This date has passed\. The batch can be recorded for history but cannot be dispensed\./,
    );
    // The page's own lists stop at 25 (pilot scale limits), so pickers search the server as Classic does.
    assert.equal((section.match(/context="references"/g) ?? []).length, 6);
    assert.match(section, /<V2Picker[\s\S]*?:options="batchChoices"/);
});

test('2d: the V2 home shows Stock setup as a V2 bar', () => {
    assert.match(
        home,
        /<V2DisclosureBar\s+v-if="referenceData\.canManage"\s+title="Stock setup"\s+keep-mounted\s*>\s*<StockSetupSection/,
    );
    assert.ok(!home.includes('<InventoryReferenceDataPanel'));
    assert.ok(!home.includes('<StockPanelBar'));
});

test("2d round 2: Daniel's changes (returns branch, item and unit in the sentence, SKU fields)", () => {
    // 1) A patient returns location must belong to a branch: "Shared" goes away and Add location says why.
    assert.match(
        section,
        /locationForm\.type === 'dispensary_returns'\s*\?\s*\[\]\s*:\s*\[\{ value: '', label: 'Shared \(no branch\)' \}\]/,
    );
    assert.match(section, /:confirm-disabled="returnsNeedsBranch"/);
    assert.match(
        section,
        /A patient returns location must belong to a branch\. Choose a branch\./,
    );
    // 2) The sentence names the item and the real unit word ("40 tablets of Paracetamol…").
    assert.match(section, /quantityWithUnit\(quantity, openingUnit\.value\)/);
    assert.match(section, /openingUnit = unit;/);
    const picker = read(
        'resources/js/components/inventory-v2/InventoryPickerField.vue',
    );
    assert.match(picker, /label: String\(row\.itemName \?\? row\.skuCode\)/);
    assert.match(
        picker,
        /emit\('picked', option\.label, String\(row\?\.stockUnit \?\? ''\)\)/,
    );

    // 3) and 4) Every SKU field is marked required, and the unclear ones say what they do.
    for (const field of [
        'pack_size',
        'unit_conversion',
        'purchase_unit',
        'stock_unit',
        'dispensing_unit',
    ]) {
        assert.match(
            section,
            new RegExp(
                `aria-hidden="true"\\s*>\\*</span\\s*><span class="sr-only">\\(required\\)</span\\s*></span\\s*>\\s*<input\\s*v-model="skuForm\\.${field}"\\s*required`,
            ),
            field,
        );
    }

    assert.match(
        section,
        /Recorded\s+only,\s+not\s+used\s+in\s+any\s+count\.\s+Leave\s+1\./,
    );
    assert.match(
        section,
        /Must\s+be\s+the\s+same\s+as\s+the\s+stock\s+unit,\s+or\s+Dispensary\s+will\s+not\s+dispense\s+this\s+SKU\./,
    );
});
