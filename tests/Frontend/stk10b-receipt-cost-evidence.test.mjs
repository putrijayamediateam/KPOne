import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('both receiving forms ask for the total paid, optional, with one help line each', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );
    const lots = read(
        'resources/js/components/inventory/VerifiedReceivingForm.vue',
    );
    assert.match(panel, /Total paid for this batch \(RM, optional\)/);
    assert.match(panel, /total paid for this batch RM/);
    assert.match(panel, /Leave blank to\s+use the order's estimate\./);
    // Design review (Daniel): the help sits under the cost field it describes, never under the delivery reference.
    assert.match(
        panel,
        /:aria-describedby="`\$\{line\.publicId\}-cost-help`"[\s\S]{0,400}step="0\.01"\s*\/><\/label>\s*<p\s+:id="`\$\{line\.publicId\}-cost-help`"/,
    );
    assert.doesNotMatch(panel, /Optional\. Type the batch total/);
    assert.doesNotMatch(panel, /Actual unit cost \(RM\)/);
    assert.match(lots, /Total paid for this lot \(RM, optional\)/);
    assert.match(
        lots,
        /v-if="entry\.outcome !== 'rejected'"\s+class="grid min-w-0 gap-1"\s*>\s*<label class="grid gap-1 text-sm"\s+>Total paid/,
    );
    assert.match(
        lots,
        /\/><\/label>\s*<p\s+:id="`\$\{row\.publicId\}-\$\{index\}-\$\{number\}-lot-cost-help`"/,
    );
    assert.doesNotMatch(lots, /Optional\. Type each lot total/);
    assert.match(lots, /cost_total_sen:/);
    assert.doesNotMatch(lots, /unit_cost_sen/);
    // Exact RM to sen without floating point.
    assert.match(lots, /Number\(match\[1\]\) \* 100 \+/);
    // Vue's type="number" v-model hands back a number: the converter must read it as text.
    assert.match(lots, /String\(value \?\? ''\)\.trim\(\)/);
    assert.doesNotMatch(lots, /parseFloat|Math\.round/);
});

test('the order estimate says it is per stock unit, not per box', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );
    assert.match(panel, /Estimated cost per stock unit \(RM\)/);
    assert.match(panel, /not one box\./);
    assert.match(panel, /aria-describedby="po-estimate-help"/);
    assert.doesNotMatch(panel, /Estimated purchase unit cost/);
});

test('a remembered draft with the old per-unit cost is not restored', () => {
    assert.match(
        read('resources/js/types/inventory-operations.ts'),
        /!\('unit_cost_sen' in line\)/,
    );
});
