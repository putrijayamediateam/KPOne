import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// DSP-18: a GS1 code names the batch and expiry on the box. The page points at matching batches and never selects one.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const load = (path) => {
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(read(path), {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, module },
    );

    return module.exports;
};
const { boxBatchHint } = load('resources/js/lib/gs1-batch.ts');
const scan = load('resources/js/lib/scan.ts');
const show = read('resources/js/pages/Dispensary/Show.vue');
const scanInput = read('resources/js/components/inventory/ScanInput.vue');
const flat = show.replace(/\s+/g, ' ');

const HINT = 'Pick the batch printed on the box.';
const date = (value) => `D(${value})`;
const options = [
    { batchNumber: 'AB12', expiryDate: '2027-10-31' },
    { batchNumber: 'ZZ99', expiryDate: '2028-01-31' },
    { batchNumber: 'ab12 ', expiryDate: '2027-10-31' },
];
const gs1 = (batch, expiry, certain = true) => ({
    gtin: '09556123000010',
    batch: certain ? batch : null,
    batch_unconfirmed: certain ? null : batch,
    expiry,
    complete: certain,
});

test('a plain barcode keeps the DSP-17 hint and marks nothing', () => {
    assert.deepEqual(
        JSON.parse(JSON.stringify(boxBatchHint(null, options, date, HINT))),
        {
            message: HINT,
            matches: [],
            warn: false,
            named: false,
            sameBatch: [],
        },
    );
    assert.equal(
        boxBatchHint(gs1(null, null), options, date, HINT).message,
        HINT,
    );
});

test('a read batch marks every matching option, trimmed and case-insensitive, with the expiry', () => {
    const hint = boxBatchHint(gs1('AB12', '2027-10-31'), options, date, HINT);
    assert.deepEqual([...hint.matches], [0, 2]);
    assert.equal(hint.warn, false);
    assert.equal(
        hint.message,
        'Box: batch AB12, exp D(2027-10-31). 2 batches in the list match it (different locations). Pick the right one yourself.',
    );
    assert.equal(hint.named, true);
    const one = boxBatchHint(gs1('ZZ99', null), options, date, HINT);
    assert.deepEqual([...one.matches], [1]);
    assert.equal(
        one.message,
        'Box: batch ZZ99. One batch in the list matches it. Pick it yourself.',
    );
    // A different expiry is not the same batch.
    assert.deepEqual(
        [
            ...boxBatchHint(gs1('ZZ99', '2028-02-29'), options, date, HINT)
                .matches,
        ],
        [],
    );
});

test('a batch that is not in the list, or only on hold, warns and marks no usable batch', () => {
    const missing = boxBatchHint(gs1('QQ1', null), options, date, HINT);
    assert.equal(missing.warn, true);
    assert.equal(
        missing.message,
        'Box: batch QQ1. No batch in the list matches it. Check the box.',
    );
    const otherExpiry = boxBatchHint(
        gs1('ZZ99', '2028-01-30'),
        options,
        date,
        HINT,
    );
    assert.equal(otherExpiry.warn, true);
    assert.deepEqual([...otherExpiry.matches], []);
    // Same batch number, other expiry: the page does not say the selected batch is a different one.
    assert.deepEqual([...otherExpiry.sameBatch], [1]);
    assert.equal(
        otherExpiry.message,
        'Box: batch ZZ99, exp D(2028-01-30). Batch ZZ99 is in the list, but its expiry is different. Check the box.',
    );
    const held = boxBatchHint(
        gs1('AB12', null),
        [{ batchNumber: 'AB12', expiryDate: '2027-10-31', held: true }],
        date,
        HINT,
    );
    assert.equal(held.warn, true);
    assert.deepEqual([...held.matches], []);
    const someHeld = boxBatchHint(
        gs1('AB12', null),
        [
            { batchNumber: 'AB12', expiryDate: '2027-10-31', held: true },
            { batchNumber: 'AB12', expiryDate: '2027-10-31' },
        ],
        date,
        HINT,
    );
    assert.deepEqual([...someHeld.matches], [1]);
    assert.match(held.message, /matches a batch on hold, which cannot be used/);
});

test('a batch read without separators is used only on an exact match, never guessed', () => {
    const swallowed = boxBatchHint(
        gs1('AB1221SERIAL9', '2027-10-31', false),
        options,
        date,
        HINT,
    );
    assert.deepEqual([...swallowed.matches], []);
    assert.equal(swallowed.warn, false);
    assert.equal(
        swallowed.message,
        `Box: exp D(2027-10-31). The batch could not be read from this code. ${HINT}`,
    );
    assert.deepEqual(
        [
            ...boxBatchHint(gs1('ZZ99', null, false), options, date, HINT)
                .matches,
        ],
        [1],
    );
    assert.equal(
        boxBatchHint(gs1(null, '2027-10-31'), options, date, HINT).message,
        `Box: exp D(2027-10-31). ${HINT}`,
    );
});

test('the scan box keeps the GS separator, drops every other control character and passes the read on', () => {
    assert.equal(
        scan.cleanScannedCode('\u00020109556123000010\u001D10AB12\r\n'),
        '0109556123000010\u001D10AB12',
    );
    // A zero-width space (U+200B) and another control character are still dropped.
    assert.equal(scan.cleanScannedCode(`A${'\u200B'}1\u001E`), 'A1');
    assert.equal(
        scan.scanMessage(
            { status: 'not_found', code: '0109556123000010\u001D10AB12' },
            'dispensary',
        ),
        'No medicine has the code 0109556123000010 10AB12. Check the code, or check the box by eye.',
    );
    assert.ok(
        scanInput.includes("emit('found', result.sku, result.gs1 ?? null);"),
    );
});

test('the page names the box and marks options, and a GS1 scan never selects a batch', () => {
    assert.ok(flat.includes('boxRead.value = gs1;'));
    // The marker leads the option text, so it shows in the closed dropdown (Daniel).
    assert.equal(flat.includes("' · matches the box'"), false);
    // When the dropdown shows another batch than the box's, the line says so; it never changes the choice.
    assert.ok(
        flat.includes(
            'message: `${hint.message} The selected batch is not the one on the box.`',
        ),
    );
    assert.ok(
        flat.includes(
            'hint.named && (selected === undefined || !hint.sameBatch.includes(selected))',
        ),
    );
    assert.equal(
        (
            flat.match(
                /\? 'Matches the box · ' : '' \}\}\s*\{\{\s*batchOptionText\(/g,
            ) ?? []
        ).length,
        2,
    );
    assert.equal(
        (flat.match(/\.warn && 'text-warning-strong'/g) ?? []).length,
        2,
    );
    const hint = show.slice(
        show.indexOf('const boxRead'),
        show.indexOf('const pointAtBatch'),
    );
    const marked = show.slice(
        show.indexOf('const markScanned'),
        show.indexOf('const clearBatchHint'),
    );

    for (const part of [hint, marked]) {
        assert.equal(/availabilityIndex\s*=/.test(part), false);
    }

    const lib = read('resources/js/lib/gs1-batch.ts');
    assert.equal(/availabilityIndex|\.value\s*=/.test(lib), false);
});
