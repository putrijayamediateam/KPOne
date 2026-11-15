import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/treatment-usage.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module },
);
const lib = module.exports;
const page = read('resources/js/pages/Inventory/TreatmentUsage.vue');
const index = read('resources/js/pages/Inventory/Index.vue');

const reasons = {
    usage: [
        'procedure',
        'wound_care',
        'nebulisation',
        'injection',
        'other_clinical_use',
    ],
    waste: [
        'opened_not_used',
        'spilled_or_broken',
        'contaminated',
        'other_waste',
    ],
};

test('switching kind always picks a reason the server accepts for that kind', () => {
    assert.equal(lib.firstReason(reasons, 'usage'), 'procedure');
    assert.equal(lib.firstReason(reasons, 'waste'), 'opened_not_used');
    assert.equal(lib.firstReason({}, 'waste'), '');
});

test('every server reason, reversal reason and ineligibility has a readable label', () => {
    for (const reason of [...reasons.usage, ...reasons.waste]) {
        assert.ok(lib.usageReasonLabels[reason], reason);
    }

    for (const reason of [
        'recorded_in_error',
        'wrong_item_or_batch',
        'wrong_quantity',
        'wrong_location',
    ]) {
        assert.ok(lib.usageReversalLabels[reason], reason);
    }

    for (const reason of ['expiry', 'held']) {
        assert.ok(lib.ineligibleBatchLabels[reason], reason);
    }
});

test('unusable batches point to the governed Write-off and every submission carries a retry key', () => {
    assert.match(page, /v-if="row\.usable"/);
    assert.match(page, /href="\/inventory\/writeoffs"/);
    assert.equal(lib.usageReasonLabels.expired_in_room, undefined);
    assert.match(page, /form\.retry_key = crypto\.randomUUID\(\)/g);
    assert.match(page, /v-if="row\.canReverse"/);
    assert.match(page, /reason: data\.reversal_reason/);
    assert.match(page, /Do not enter patient details/);
    assert.doesNotMatch(page, /v-html/);
});

test('the Inventory page links to treatment room usage', () => {
    assert.match(index, /href="\/inventory\/treatment-usage"/);
});
