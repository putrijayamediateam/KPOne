import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// Q-9 (owner decision 2026-11-18): a new medicine order row starts from the catalogue defaults and the usual quantity.
// The helpers are executed from the real source file, not copied.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const lib = read('resources/js/lib/treatment-prefill.ts');
const javascript = ts.transpileModule(lib.replaceAll('export ', ''), {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const { trimDecimal, prefillTexts, INDICATION_LABEL_LIMIT } = runInNewContext(
    `${javascript}\n({ trimDecimal, prefillTexts, INDICATION_LABEL_LIMIT })`,
);
const panel = read(
    'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
);

test('decimals from the catalogue lose their trailing zeros and nothing else', () => {
    assert.equal(trimDecimal('12.000'), '12');
    assert.equal(trimDecimal('0.500'), '0.5');
    assert.equal(trimDecimal('2'), '2');
    assert.equal(trimDecimal('1.250'), '1.25');

    for (const bad of [null, undefined, '', '0', '0.000', '-1', 'abc', '1e3']) {
        assert.equal(trimDecimal(bad), '', String(bad));
    }
});

test('a blank usual quantity keeps the quantity of 1 and no defaults means no prefill note', () => {
    const none = prefillTexts({ unit: 'tablet' });
    assert.equal(none.quantity, '1');
    assert.equal(none.usedDefaults, false);
    assert.equal(none.dosage, '');
    const usual = prefillTexts({ unit: 'tablet', usualQuantity: '10.000' });
    assert.equal(usual.quantity, '10');
    assert.equal(usual.usedDefaults, true);
});

test('every catalogue default is carried and the dosage needs both amount and unit', () => {
    const text = prefillTexts({
        unit: 'tablet',
        usualQuantity: '15.000',
        defaultDosageAmount: '1.000',
        defaultDosageUnit: 'tablet',
        defaultFrequency: 'Three times daily',
        defaultDuration: '5 days',
        defaultInstruction: ' After food ',
        defaultPrecaution: 'May cause drowsiness',
        defaultIndication: 'Fever',
    });
    assert.deepEqual(
        { ...text },
        {
            quantity: '15',
            dosage: '1 tablet',
            frequency: 'Three times daily',
            duration: '5 days',
            instruction: 'After food',
            precaution: 'May cause drowsiness',
            indication: 'Fever',
            usedDefaults: true,
            indicationTooLong: false,
        },
    );
    assert.equal(
        prefillTexts({ unit: 'x', defaultDosageAmount: '1.000' }).dosage,
        '',
    );
    assert.equal(
        prefillTexts({ unit: 'x', defaultDosageUnit: 'tablet' }).dosage,
        '',
    );
});

test('an indication over the 72-character label budget is flagged, 72 exactly is not', () => {
    assert.equal(INDICATION_LABEL_LIMIT, 72);
    assert.equal(
        prefillTexts({ unit: 'x', defaultIndication: 'a'.repeat(72) })
            .indicationTooLong,
        false,
    );
    assert.equal(
        prefillTexts({ unit: 'x', defaultIndication: 'a'.repeat(73) })
            .indicationTooLong,
        true,
    );
});

test('the panel prefills only a NEW row, through the same parsers a saved order uses, and says so', () => {
    const add = panel.slice(
        panel.indexOf('const addMedicine ='),
        panel.indexOf('const syncMedicine ='),
    );
    assert.match(add, /prefillTexts\(item\)/);
    assert.match(
        add,
        /parseAmountUnit\(\s*prefill\.dosage,\s*dosageUnits\s*\)/,
    );
    assert.match(add, /selectValue\(prefill\.frequency,\s*frequencyPresets\)/);
    assert.match(
        add,
        /parseAmountUnit\(\s*prefill\.duration,\s*durationUnits,\s*true,?\s*\)/,
    );
    assert.match(add, /quantity_ordered: prefill\.quantity/);
    assert.match(add, /syncMedicine\(row\);\s*form\.medicines\.push\(row\);/);
    // the existing guard still stops a second row for the same medicine, so a touched row is never overwritten
    assert.match(
        add,
        /form\.medicines\.some\(\(row\) => row\.catalogue_public_id === item\.publicId\)/,
    );
    assert.match(panel, /data-testid="prefill-note"/);
    assert.match(panel, /Prefilled from the catalogue\. Check the quantity/);
    assert.match(panel, /data-testid="prefill-indication-warning"/);
    // presentation state only: the note flags never join the saved form
    assert.doesNotMatch(add, /prefilled:/);
});
