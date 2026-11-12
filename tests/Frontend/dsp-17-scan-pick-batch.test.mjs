import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-17: a scan points at the batch to pick. Barcodes carry no batch, so the CA picks it: nothing is selected.
const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);
const flat = show.replace(/\s+/g, ' ');

test('a matching scan points at the line batch dropdown and never selects a batch', () => {
    assert.ok(
        show.includes(
            "const BATCH_HINT = 'Pick the batch printed on the box.';",
        ),
    );
    assert.ok(flat.includes('void pointAtBatch(line.publicId);'));
    assert.ok(flat.includes("block: 'center'"));
    assert.ok(flat.includes("behavior: reduced ? 'auto' : 'smooth'"));
    // Focus stays in the scan box so the next box can be scanned straight away.
    assert.equal(flat.includes('select.focus('), false);
    // The scan never changes the selection.
    const point = show.slice(
        show.indexOf('const pointAtBatch'),
        show.indexOf('const markScanned'),
    );
    assert.equal(/availabilityIndex\s*=/.test(point), false);
    assert.equal(/showPicker|\.click\(\)/.test(point), false);
});

test('the hint is a brand ring and a polite status line, not the error look', () => {
    assert.ok(
        show.includes(
            "'border-primary ring-2 ring-primary ring-offset-2 ring-offset-background focus-visible:ring-primary'",
        ),
    );
    const hintClass = show.slice(show.indexOf('const batchHintClass'));
    assert.equal(
        hintClass.slice(0, hintClass.indexOf(';')).includes('destructive'),
        false,
    );
    assert.equal(
        (show.match(/data-testid="batch-pick-hint"/g) ?? []).length,
        2,
    );
});

test('the hint clears on a change, a new scan or a save', () => {
    assert.ok(flat.includes('@change="clearBatchHint(item.publicId)"'));
    assert.ok(flat.includes(`@change="clearBatchHint('add')"`));
    assert.ok(flat.includes('clearBatchHint(item.publicId); done?.();'));
    assert.ok(
        flat.includes(
            'const outcome = scanOutcome(sku); batchHint.value = null;',
        ),
    );
    // A refused or unknown scan clears it too, and a removed line never keeps it (Hakim).
    const accept = show.slice(show.indexOf('const acceptScan'));
    assert.ok(
        accept.slice(0, accept.indexOf('};')).includes('clearLineHint();'),
    );
    assert.ok(flat.includes('@keydown.enter.capture="clearLineHint"'));
    assert.ok(flat.includes('watch(visibleItems, (items) => {'));
    assert.ok(flat.includes("chosen.value = null; clearBatchHint('add');"));
    // Scan to add highlights the Add form batch but keeps focus on Quantity.
    assert.ok(
        flat.includes(
            "chooseMedicine(outcome.medicine); batchHint.value = 'add';",
        ),
    );
});
