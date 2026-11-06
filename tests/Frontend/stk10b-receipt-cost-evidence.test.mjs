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
    assert.match(panel, /Total paid for this batch \(RM\)/);
    assert.match(panel, /total paid for this batch RM/);
    assert.match(panel, /Leave blank to use the order's estimate\./);
    assert.match(panel, /:aria-describedby="`\$\{row\.publicId\}-cost-help`"/);
    assert.doesNotMatch(panel, /Actual unit cost \(RM\)/);
    assert.match(lots, /Total paid for this lot \(RM\)/);
    assert.match(
        lots,
        /v-if="entry\.outcome !== 'rejected'"\s+class="grid gap-1 text-sm"\s+>Total paid/,
    );
    assert.match(lots, /cost_total_sen:/);
    assert.doesNotMatch(lots, /unit_cost_sen/);
    // Exact RM to sen without floating point.
    assert.match(lots, /Number\(match\[1\]\) \* 100 \+/);
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
