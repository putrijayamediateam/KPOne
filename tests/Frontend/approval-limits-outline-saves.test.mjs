import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Daniel's note (2026-11-16): Billing Approval Limits had a filled Save on every
// approver row. The row Saves are outline; "Save threshold" stays the page's one
// filled action.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/BillingApprovalLimits/Index.vue');
const threshold = read(
    'resources/js/components/billing/InvoiceAdjustmentThreshold.vue',
);

test('approval limit row Saves are outline buttons', () => {
    for (const kind of ['panel', 'deferment']) {
        assert.match(
            page,
            new RegExp(
                // DD-103/DD-104 (owner approved 2026-11-17): other settings may sit before the click.
                `<Button\\s+size="sm"\\s+variant="outline"[^>]*@click="saveLimit\\(row, '${kind}'\\)"`,
            ),
            kind,
        );
    }
});

test('Save threshold stays the one filled action', () => {
    const button = threshold.match(/<Button[^>]*>\s*Save threshold/);
    assert.ok(button, 'Save threshold button');
    assert.doesNotMatch(button[0], /variant=/);
});
