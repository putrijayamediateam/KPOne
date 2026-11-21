import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');

const javascript = ts.transpileModule(
    read('resources/js/lib/dispensary-packs.ts').replaceAll('export ', ''),
    {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.CommonJS,
        },
    },
).outputText;
const packs = runInNewContext(
    `${javascript}\n({ usablePackConversion, packCount, packChoiceLabel, parsePacks, packsToBase, basesToPacks, sameQuantity, conversionLine, packsOnHand })`,
    { exports: {} },
);
const bottle = {
    publicId: 'c1',
    version: 1,
    packUnit: 'bottle',
    basePerPack: 60,
    baseUnit: 'ml',
};

const field = squash(
    read('resources/js/components/dispensary/PackQuantityField.vue'),
);
const page = squash(read('resources/js/pages/Dispensary/Show.vue'));

test('DSP-24: whole bottles only, 1 to 99, converted exactly to ml', () => {
    assert.equal(packs.parsePacks('2'), 2);
    assert.equal(packs.parsePacks(' 99 '), 99);

    for (const bad of ['0', '100', '1.5', '-1', '', 'two', '1e1']) {
        assert.equal(packs.parsePacks(bad), null, bad);
    }

    assert.equal(packs.packsToBase(2, bottle), '120.000');
    assert.equal(packs.conversionLine(2, bottle), '2 bottles = 120 ml');
    assert.equal(packs.conversionLine(1, bottle), '1 bottle = 60 ml');
    assert.equal(packs.packCount(2, 'box'), '2 boxes');
    assert.equal(packs.packChoiceLabel('bottle'), 'Bottles');
});

test('DSP-24: switching to bottles converts only whole bottles and never rounds', () => {
    assert.equal(packs.basesToPacks('120.000', bottle), 2);
    assert.equal(packs.basesToPacks('120', bottle), 2);
    assert.equal(packs.basesToPacks('90.000', bottle), null);
    assert.equal(packs.basesToPacks('0', bottle), null);
    assert.equal(
        packs.basesToPacks('6000.000', bottle),
        null,
        'over 99 bottles',
    );
    assert.equal(packs.sameQuantity('60', '60.000'), true);
    assert.equal(packs.sameQuantity('60', '120.000'), false);
});

test('DSP-24: bottles are offered only for the line’s own unit, and the batch shows them only when they divide evenly', () => {
    assert.equal(packs.usablePackConversion(bottle, 'ml'), bottle);
    assert.equal(packs.usablePackConversion(bottle, 'tablet'), null);
    assert.equal(packs.usablePackConversion(null, 'ml'), null);
    assert.equal(packs.packsOnHand('180.000', bottle), ' (3 bottles)');
    assert.equal(packs.packsOnHand('150.000', bottle), '');
    assert.equal(packs.packsOnHand('180.000', null), '');
});

test('DSP-24: the quantity box starts on ml, says why a number is refused, and reads the conversion politely', () => {
    assert.match(
        field,
        /defineModel<'base' \| 'packs'>\('mode', \{ default: 'base' \}\)/,
    );
    assert.match(field, /only, 1 to \$\{MAX_PACKS\}\./);
    assert.match(field, /aria-live="polite"/);
    assert.match(
        field,
        /Doctor ordered \{\{ displayQuantity\(ordered!\) \}\} \{\{ unit \}\}\. This is \{\{ displayQuantity\(quantity\) \}\} \{\{ unit \}\}\./,
    );
    assert.match(field, /<legend class="sr-only">Quantity unit<\/legend>/);
});

test('DSP-24: the page sends the packs with the conversion version it showed, and keeps the old field hooks', () => {
    assert.match(page, /pack_conversion_public_id: usable\.publicId/);
    assert.match(page, /pack_conversion_version: usable\.version/);
    assert.match(
        page,
        /\.\.\.packPayload\(form, item\.packConversion, item\.unit\)/,
    );
    assert.match(
        page,
        /\.\.\.packPayload\(addForm, medicine\.packConversion, medicine\.unit\)/,
    );
    assert.ok(page.includes('data-field="quantity"'));
    assert.ok(page.includes('data-field="add-quantity"'));
    assert.match(page, /packMode: item\.packsDispensed \? 'packs' : 'base'/);
    // Daniel: Save line and Add stay off while the bottles number is not 1 to 99.
    assert.match(
        page,
        /form\?\.packMode === 'packs' && parsePacks\(form\.packs\) === null/,
    );
    assert.match(page, /packsInvalid\(forms\[item\.publicId\]\) \|\|/);
    assert.match(
        page,
        /!addForm\.frequency\.trim\(\) \|\| packsInvalid\(addForm\)/,
    );
    // Daniel: Save all lines checks every line first and names the invalid one, saving nothing.
    assert.match(
        page,
        /enter whole \$\{packs\}, 1 to 99, before saving\. Nothing was saved\./,
    );
    // Daniel: the doctor's quantity beside the new warning drops trailing zeros ("60 ml", never "60.000 ml").
    assert.match(page, /\{\{ displayQuantity\(item\.quantityOrdered\) \}\}/);
});

test('DSP-24: the doctor, Completed, Invoices and returns screens show "2 bottles (120 ml)" when the line used bottles', () => {
    assert.match(
        squash(
            read(
                'resources/js/pages/Clinical/Partials/DispensaryCheckPanel.vue',
            ),
        ),
        /v-if="line\.prepared\.packText"/,
    );
    assert.match(
        squash(read('resources/js/pages/Dispensary/Review.vue')),
        /v-if="medicine\.dispensed\.packText"/,
    );
    assert.match(
        squash(read('resources/js/pages/Visits/History.vue')),
        /v-if="medicine\.dispensedPacks"/,
    );
    assert.match(
        squash(
            read(
                'resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
            ),
        ),
        /line\.dispensedPacks \?\?/,
    );
});
