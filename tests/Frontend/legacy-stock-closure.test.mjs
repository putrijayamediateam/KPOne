import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const panel = readFileSync(
    new URL(
        '../../resources/js/components/inventory/InventoryOperationsPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('legacy stock controls and hidden handlers are absent while history and governed links remain', () => {
    assert.doesNotMatch(
        panel,
        /confirmAdjustment|confirmStocktakeTransition|createStocktake|transitionStocktake|adjustmentIdentity|physicalCounts/,
    );
    assert.doesNotMatch(panel, /inventory\/stocktakes|inventory\/adjustments/);
    assert.match(panel, /Legacy stocktake and manual adjustment are read-only/);

    for (const destination of ['counts', 'writeoffs']) {
        assert.match(panel, new RegExp(`href="/inventory/${destination}"`));
    }

    assert.equal((panel.match(/min-h-11/g) ?? []).length >= 2, true);

    for (const retained of [
        'operations.stocktakes',
        'operations.adjustments',
        'expectedQuantity',
        'physicalQuantity',
        'varianceQuantity',
        'saveReorder',
        'receivePurchaseOrder',
        'dispatchStockRequest',
    ]) {
        assert.ok(panel.includes(retained), retained);
    }
});
