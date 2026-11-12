import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// DSP-20 (owner: "go with DSP-20"): scan to check also compares the batch on a GS1 box with the line's saved batches.
// It warns only: nothing is saved, selected or blocked.
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
const { boxBatchCheck } = load('resources/js/lib/dispensary-scan.ts');
const show = read('resources/js/pages/Dispensary/Show.vue');
const flat = show.replace(/\s+/g, ' ');

const saved = [
    { batchPublicId: 'b1', batchNumber: 'PCM2401', expiryDate: '2027-07-10' },
    { batchPublicId: 'b2', batchNumber: 'PCM2405', expiryDate: '2028-11-10' },
];
const gs1 = (batch, expiry = null, certain = true) => ({
    gtin: '09556123000010',
    batch: certain ? batch : null,
    batch_unconfirmed: certain ? null : batch,
    expiry,
    complete: certain,
});

test('a box batch saved on the line matches, ignoring case and spaces', () => {
    const result = boxBatchCheck(gs1(' pcm2405', '2028-11-10'), saved);

    assert.equal(result.kind, 'matches');
    assert.equal(result.warn, false);
    assert.equal(result.message, 'Batch pcm2405 matches the box.');
});

test('a box batch that is not saved on the line warns', () => {
    const result = boxBatchCheck(gs1('XYZ9', '2027-07-10'), saved);

    assert.equal(result.kind, 'differs');
    assert.equal(result.warn, true);
    assert.equal(
        result.message,
        'Box batch XYZ9 is not the batch saved on this line. Check the box.',
    );
});

test('the same batch number with another expiry warns', () => {
    const result = boxBatchCheck(gs1('PCM2401', '2027-08-10'), saved);

    assert.equal(result.kind, 'expiry_differs');
    assert.equal(result.warn, true);
    assert.equal(
        result.message,
        'Box batch PCM2401 is saved on this line, but its expiry is different. Check the box.',
    );
});

test('a box with a batch but no expiry is compared by batch only', () => {
    assert.equal(boxBatchCheck(gs1('PCM2401'), saved).kind, 'matches');
});

test('nothing to compare: a plain barcode, no batch, or no saved batch', () => {
    assert.equal(boxBatchCheck(null, saved), null);
    assert.equal(boxBatchCheck(gs1(null, '2027-07-10'), saved), null);
    assert.equal(boxBatchCheck(gs1('PCM2401'), []), null);
    assert.equal(
        boxBatchCheck(gs1('PCM2401'), [
            { batchPublicId: 'b1', batchNumber: null },
        ]),
        null,
    );
});

test('a batch read without separators is used only when it matches exactly', () => {
    assert.equal(
        boxBatchCheck(gs1('PCM2401', null, false), saved).kind,
        'matches',
    );
    assert.equal(
        boxBatchCheck(gs1('PCM240117270710', null, false), saved),
        null,
    );
});

test('the page keeps the read per checked line and compares it live with the saved batches', () => {
    assert.ok(
        flat.includes(
            'const scanBoxReads = ref<Record<string, Gs1Read | null>>({});',
        ),
    );
    assert.ok(
        flat.includes(
            'boxBatchCheck( scanBoxReads.value[item.publicId] ?? null, item.allocations ?? [], )',
        ),
    );
    assert.ok(flat.includes('isScanChecked(item) ? boxBatchCheck('));
    // A line that loses its mark loses its read.
    assert.ok(
        flat.includes(
            '([publicId]) => scanMarks.value[publicId] !== undefined',
        ),
    );
});

test('the note shows under the Checked by scan badge, in warning or success colour, and is spoken once', () => {
    assert.ok(flat.includes('v-if="scanBatch(item)"'));
    assert.ok(
        flat.includes(
            "scanBatch(item)?.warn ? 'text-warning-strong' : 'text-success'",
        ),
    );
    assert.ok(
        flat.includes(':data-testid="`scan-batch-${scanBatch(item)?.kind}`"'),
    );
    assert.ok(
        flat.includes(
            'role="status" class="sr-only" data-testid="scan-batch-announcement"',
        ),
    );
    assert.ok(flat.includes("scanBatchAnnouncement.value = '';"));
});

test('it never selects, saves or blocks anything', () => {
    const marked = show.slice(
        show.indexOf('const markScanned'),
        show.indexOf('const clearBatchHint'),
    );

    assert.equal(/availabilityIndex\s*=/.test(marked), false);
    assert.equal(/router\.|\.post\(|\.put\(|\.patch\(/.test(marked), false);

    for (const disabled of show.match(/:disabled="[^"]*"/g) ?? []) {
        assert.equal(/scanBatch|scanBoxReads/.test(disabled), false, disabled);
    }
});
