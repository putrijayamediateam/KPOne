import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// DSP-15 (owner choice 2026-11-10): a return quarantined across the batches its line was dispensed from.
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
const lib = load('resources/js/lib/dispensary-returns.ts');
const panel = read(
    'resources/js/pages/Dispensary/Partials/ReturnCheckPanel.vue',
);
const section = read(
    'resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
);

test('typed quantities convert to thousandths and back, with up to 3 decimals', () => {
    assert.equal(lib.toThousandths(''), 0);
    assert.equal(lib.toThousandths(' 2 '), 2000);
    assert.equal(lib.toThousandths('2.5'), 2500);
    assert.equal(lib.toThousandths('0.125'), 125);
    assert.equal(lib.toThousandths('2.0001'), null);
    assert.equal(lib.toThousandths('-1'), null);
    assert.equal(lib.toThousandths('1,5'), null);
    assert.equal(lib.fromThousandths(3000), '3');
    assert.equal(lib.fromThousandths(1500), '1.5');
    assert.equal(lib.fromThousandths(125), '0.125');
});

test('the history line names the batch, or each batch with its quantity', () => {
    assert.equal(lib.batchesLine([], 'tablet'), null);
    assert.equal(
        lib.batchesLine(
            [{ label: 'B1 · exp 30/10/2027', quantity: '2' }],
            'tablet',
        ),
        'Batch B1 · exp 30/10/2027',
    );
    assert.equal(
        lib.batchesLine(
            [
                { label: 'B1 · exp 30/10/2027', quantity: '3' },
                { label: 'B2 · exp 14/03/2028', quantity: '1.5' },
            ],
            'tablet',
        ),
        'Batches: B1 · exp 30/10/2027 (3 tablet), B2 · exp 14/03/2028 (1.5 tablet)',
    );
    assert.ok(section.includes('data-testid="return-check-batches"'));
    assert.ok(section.includes('batchesLine('));
});

test('a line from two or more batches gets one box per batch, empty meaning 0, never prefilled', () => {
    assert.ok(
        panel.includes(
            'const isSplit = computed(() => props.line.batches.length > 1);',
        ),
    );
    assert.ok(panel.includes('Batches the units came from'));
    assert.ok(panel.includes('Leave 0 for a batch'));
    assert.ok(panel.includes('inputmode="decimal"'));
    assert.ok(panel.includes('placeholder="0"'));
    assert.ok(panel.includes('`Quantity from batch ${batch.label}`'));
    assert.ok(panel.includes("'None left to return from this batch'"));
    assert.ok(panel.includes('`Available: ${batch.available} ${line.unit}`'));
    // Boxes start empty; the helper fills only when pressed and never over the checker's own numbers.
    assert.ok(
        panel.includes("props.line.batches.map((b) => [b.publicId, ''])"),
    );
    assert.ok(panel.includes('Fill from earliest expiry'));
    assert.ok(panel.includes(':disabled="form.processing || anyTyped"'));
    assert.ok(
        panel.includes('Use only if the boxes in hand show these batches.'),
    );
    assert.ok(
        panel.includes("'Filled from earliest expiry. Check the boxes match.'"),
    );
});

test('the placed count is announced politely after a pause, and errors use the agreed wording', () => {
    assert.ok(panel.includes('role="status" aria-live="polite"'));
    assert.ok(panel.includes('}, 500);'));
    assert.ok(panel.includes('· All placed'));
    assert.ok(panel.includes('left`'));
    assert.ok(panel.includes('too many`'));
    assert.ok(
        panel.includes(
            "'Enter a number with up to 3 decimals, for example 2.5.'",
        ),
    );
    assert.ok(
        panel.includes(
            '`At most ${batch.available} ${props.line.unit} from this batch.`',
        ),
    );
    assert.ok(
        panel.includes("'Enter how many units went back to each batch.'"),
    );
    assert.ok(
        panel.includes(
            'They must add up to ${props.row.quantity} ${props.line.unit}.',
        ),
    );
    // A server message for batches.N lands under the box that was sent N-th.
    assert.ok(panel.includes('errors[`batches.${index}.quantity`]'));
});

test('a split sends only the shares above 0, and the confirm dialog lists them', () => {
    assert.ok(
        panel.includes(
            '.filter((share) => (toThousandths(share.quantity) ?? 0) > 0)',
        ),
    );
    assert.ok(panel.includes('? sharesToSend.value'));
    assert.ok(panel.includes('data-testid="return-check-dialog-batches"'));
    assert.ok(panel.includes('From {{ dialogBatches.length }} batches:'));
    assert.ok(panel.includes('From batch {{ dialogBatches[0].label }}'));
});

test('quarantine is disabled with a reason when the batches cannot take the return back', () => {
    assert.ok(panel.includes('quarantineBlocked'));
    assert.ok(
        panel.includes(
            "can go back to this line's batches. Dispose the return, or refuse it if it was recorded wrongly.",
        ),
    );
});

test('review fixes: batch errors still show, typing clears only batch messages, focus goes to a box', () => {
    // A server refusal on batch_public_id is shown on a one-batch line.
    assert.ok(panel.includes('v-if="!isSplit && form.errors.batch_public_id"'));
    // Typing in a box never wipes the location message or a server alert.
    assert.ok(panel.includes("key.startsWith('batches')"));
    assert.equal(
        /watch\(\s*shares,\s*\(\) => \{\s*splitErrors\.value = \{\};\s*form\.clearErrors\(\);/.test(
            panel,
        ),
        false,
    );
    // A total message focuses the first open box.
    assert.ok(
        panel.includes(`'input[data-field="check-share"]:not(:disabled)'`),
    );
    // The dialog list is keyed by batch, not by its label.
    assert.ok(panel.includes(':key="b.publicId"'));
    // A one-batch line is blocked with a reason too.
    assert.ok(panel.includes('props.line.quarantineAvailable !== null &&'));
});
