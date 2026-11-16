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

test('PCA-3: Billing Detail and Panel invoices show the Panel adjustment as its own column', () => {
    assert.match(
        read('resources/js/pages/Reports/BillingDetail.vue'),
        /\{ key: 'panelAdjustmentSen', label: 'Panel adjustment' \}/,
    );
    const invoices = flat(read('resources/js/pages/PanelInvoices/Index.vue'));
    assert.ok(invoices.includes('Panel adjustment'));
    assert.ok(invoices.includes('row.panelAdjustmentSen'));
});
