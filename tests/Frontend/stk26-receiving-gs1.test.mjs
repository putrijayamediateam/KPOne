import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// STK-26 (option (a), hint only): a GS1 box scanned at Verified receiving names its batch and expiry on the order line.
// It never fills, adds or saves a lot; a person still types every batch and expiry (STK-3).
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
const { boxLotHint: inner } = load('resources/js/lib/receiving-gs1.ts');
// The result comes from another realm, so copy it before a strict comparison.
const boxLotHint = (...args) => {
    const hint = inner(...args);

    return hint === null ? null : { ...hint };
};
const form = read(
    'resources/js/components/inventory/VerifiedReceivingForm.vue',
);
const date = (value) => `D(${value})`;
const gs1 = (batch, expiry, certain = true) => ({
    gtin: '09556123000010',
    batch: certain ? batch : null,
    batch_unconfirmed: certain ? null : batch,
    expiry,
    complete: certain,
});
const lots = (...entries) =>
    entries.map(([batch_number, expiry_date]) => ({
        batch_number,
        expiry_date,
    }));

test('STK-26: a plain barcode adds nothing to the STK-9 Scanned mark', () => {
    assert.equal(boxLotHint(null, lots(['', '']), date), null);
    assert.equal(boxLotHint(gs1(null, null), lots(['', '']), date), null);
});

test('STK-26: the box is named, and a typed lot that matches it is pointed at', () => {
    assert.deepEqual(
        boxLotHint(gs1('AB12', '2027-10-31'), lots(['', '']), date),
        {
            message:
                'Box: batch AB12, exp D(2027-10-31). No lot has this batch yet. Type it in yourself.',
            warn: false,
        },
    );
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31'),
            lots(['ZZ', '2028-01-01'], [' ab12 ', '2027-10-31']),
            date,
        ),
        {
            message: 'Box: batch AB12, exp D(2027-10-31). Matches lot 2.',
            warn: false,
        },
    );
});

test('STK-26: the same batch with another expiry warns in words', () => {
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31'),
            lots(['AB12', '2027-11-30']),
            date,
        ),
        {
            message:
                'Box: batch AB12, exp D(2027-10-31). Lot 1 has batch AB12, but a different expiry. Check the box.',
            warn: true,
        },
    );
});

test('STK-26: an uncertain batch is used only on an exact match, never shown as the box batch', () => {
    assert.deepEqual(
        boxLotHint(
            gs1('AB1217270101', '2027-10-31', false),
            lots(['AB12', '2027-10-31']),
            date,
        ),
        {
            message:
                'Box: exp D(2027-10-31). The batch could not be read from this code. Type it from the box.',
            warn: false,
        },
    );
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31', false),
            lots(['AB12', '2027-10-31']),
            date,
        ),
        {
            message: 'Box: batch AB12, exp D(2027-10-31). Matches lot 1.',
            warn: false,
        },
    );
});

test('STK-26: the form never fills, adds or saves a lot from a scan', () => {
    // The scan handler only stores what the box said and highlights the line.
    const handler = form.slice(
        form.indexOf('async function highlight'),
        form.indexOf('watch('),
    );
    assert.match(handler, /boxRead\.value = gs1;/);
    assert.doesNotMatch(
        handler,
        /batch_number|expiry_date|lots\.push|\.post\(|submit\(/,
    );
    // The hint is shown as words in a status line on the scanned line only.
    assert.match(
        form,
        /v-if="scannedLine === line\.line_public_id && boxHint"[\s\S]{0,300}role="status"/,
    );
    assert.match(form, /'font-medium text-warning-strong'/);
});
