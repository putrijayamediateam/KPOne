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

test('whoever raised or submitted an order is not offered Approve', () => {
    assert.match(panel, /&& !needsAnotherApprover\(row, action\)/);
    assert.match(
        panel,
        /row\.raisedByYou === true &&\s+\(action === 'approve' \|\|\s+\(action === 'cancel' && row\.status === 'approved'\)\)/,
    );
    assert.match(
        panel,
        /You raised or submitted this order, so a different\s+Finance Officer or Director must approve it\./,
    );
});
