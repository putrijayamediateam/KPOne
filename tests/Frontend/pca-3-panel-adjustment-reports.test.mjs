import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (src) => src.replace(/\s+/g, ' ');

test('PCA-3: Insights shows Panel claim adjustments as their own signed row, dated by confirmation', () => {
    const reconciliation = flat(
        read('resources/js/components/insights/SalesReconciliation.vue'),
    );
    assert.ok(reconciliation.includes('Panel claim adjustments'));
    assert.match(
        reconciliation,
        /totals\.panelAdjustmentsSen < 0 \? '−' : '\+'/,
    );
    assert.ok(
        reconciliation.includes(
            'Panel claim adjustments count on the day they were confirmed, so past days never change.',
        ),
    );
    assert.match(
        read('resources/js/types/insights.ts'),
        /panelAdjustmentsSen: number;/,
    );
});

test('PCA-3: Billing Detail and Panel invoices say the Panel adjustment is already inside the Panel figure', () => {
    const detail = flat(read('resources/js/pages/Reports/BillingDetail.vue'));
    assert.ok(
        detail.includes(
            "label: 'Panel adjustment (included in Approved Panel)'",
        ),
    );
    assert.ok(detail.includes("sen > 0 ? '+' : '−'"));
    const invoices = flat(read('resources/js/pages/PanelInvoices/Index.vue'));
    assert.ok(invoices.includes('Panel adjustment (included in Panel amount)'));
    assert.ok(invoices.includes("row.panelAdjustmentSen > 0 ? '+' : '−'"));
});

test('PCA-3: the Daily summary signs the adjustment column and explains its date under the tile', () => {
    const page = flat(read('resources/js/pages/Reports/BranchReport.vue'));
    assert.ok(page.includes('Counted on the day it was confirmed.'));
    assert.ok(page.includes('j === adjustmentColumn ? signed(cell) : cell'));
    assert.ok(page.includes('`−${value.slice(1)}`'));
});
