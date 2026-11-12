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

test('STK-26: a batch typed before its expiry is not a "different expiry" (Mei Ling)', () => {
    assert.deepEqual(
        boxLotHint(gs1('AB12', '2027-10-31'), lots(['AB12', '']), date),
        {
            message:
                'Box: batch AB12, exp D(2027-10-31). Lot 1 has this batch; type its expiry.',
            warn: false,
        },
    );
});

test('STK-26: several lots are named, and a conflicting lot is not hidden by a matching one', () => {
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31'),
            lots(['AB12', '2027-10-31'], ['ab12', '2027-10-31']),
            date,
        ),
        {
            message: 'Box: batch AB12, exp D(2027-10-31). Matches lots 1, 2.',
            warn: false,
        },
    );
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31'),
            lots(['AB12', '2027-10-31'], ['AB12', '2027-12-31']),
            date,
        ),
        {
            message:
                'Box: batch AB12, exp D(2027-10-31). Matches lot 1. Lot 2 has batch AB12, but a different expiry. Check the box.',
            warn: true,
        },
    );
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31'),
            lots(['AB12', '2027-11-30'], ['AB12', '2027-12-31']),
            date,
        ),
        {
            message:
                'Box: batch AB12, exp D(2027-10-31). Lots 1, 2 have batch AB12, but a different expiry. Check the box.',
            warn: true,
        },
    );
});

test('STK-26: a box with only a batch, or only an expiry', () => {
    assert.deepEqual(
        boxLotHint(gs1('AB12', null), lots(['AB12', '2027-10-31']), date),
        {
            message: 'Box: batch AB12. Matches lot 1.',
            warn: false,
        },
    );
    assert.deepEqual(
        boxLotHint(gs1(null, '2027-10-31'), lots(['AB12', '2027-10-31']), date),
        {
            message: 'Box: exp D(2027-10-31).',
            warn: false,
        },
    );
    // A blank batch from the server is treated as no batch: never "batch ,".
    assert.deepEqual(
        boxLotHint(gs1('  ', '2027-10-31'), lots(['', '']), date),
        {
            message: 'Box: exp D(2027-10-31).',
            warn: false,
        },
    );
});

test('STK-26: an uncertain batch never warns and is never shown unless it matches exactly', () => {
    assert.deepEqual(
        boxLotHint(gs1('AB12', null, false), lots(['ZZ', '']), date),
        {
            message:
                'The batch could not be read from this code. Type it from the box.',
            warn: false,
        },
    );
    assert.deepEqual(
        boxLotHint(
            gs1('AB12', '2027-10-31', false),
            lots(['AB12', '2027-12-31']),
            date,
        ),
        {
            message:
                'Box: exp D(2027-10-31). The batch could not be read from this code. Type it from the box.',
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
    // The hint only reads lots, and a refused scan clears the previous box.
    const hint = form.slice(
        form.indexOf('const boxHint = computed('),
        form.indexOf('function lineFor'),
    );
    assert.doesNotMatch(hint, /=\s*['"`]|\.push\(|\.splice\(|\.post\(/);
    const refuse = form.slice(
        form.indexOf('function onOrder'),
        form.indexOf('async function highlight'),
    );
    assert.match(refuse, /boxRead\.value = null;/);
    // The status line stays mounted while the line is the scanned one, so a change of words is announced.
    assert.match(
        form,
        /v-if="scannedLine === line\.line_public_id"\s+class="text-sm wrap-anywhere empty:sr-only"[\s\S]{0,250}role="status"/,
    );
    assert.match(form, /\{\{ boxHint\?\.message \?\? '' \}\}/);
    assert.match(form, /'font-medium text-warning-strong'/);
});
