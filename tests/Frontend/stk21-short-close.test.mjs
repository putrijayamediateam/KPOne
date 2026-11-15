import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const panel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);

test('only an approver may close the rest of a partly received order', () => {
    const lib = read('resources/js/lib/inventory-operation-transitions.ts');
    assert.match(
        lib,
        /action === 'short_close'\) \{\s*return \(\s*status === 'partially_received' &&\s*permissions\.approvePurchaseOrders === true/,
    );
    // Never whoever raised or submitted the order; a reason line says why.
    assert.match(
        panel,
        /row\.raisedByYou === true && action === 'short_close'/,
    );
    assert.match(
        panel,
        /Finance Officer or Director must close the rest of\s+it\./,
    );
    assert.match(panel, />Close the rest<\/Button/);
});

test('the dialog asks for a fixed reason and a note for Other', () => {
    for (const label of [
        'Supplier cannot supply',
        'Item discontinued',
        'Ordered elsewhere',
        "label: 'Other'",
    ]) {
        assert.ok(panel.includes(label), label);
    }

    assert.match(panel, /Why will the rest not come\? \(required\)/);
    assert.match(panel, /maxlength="200"/);
    assert.match(panel, /'Add a note to explain why\.'/);
    assert.match(panel, /'Choose why the rest of this order will not come\.'/);
    assert.match(panel, /id="short-close-note-error"\s+role="alert"/);
    assert.match(panel, /id="short-close-reason-error"\s+role="alert"/);
    assert.match(panel, /'short-close-note-error short-close-note-help'/);
    assert.match(panel, /:aria-invalid="shortCloseNoteError !== ''"/);
    assert.match(panel, /\?\.focus\(\)/);
    assert.match(
        panel,
        /This cannot be undone\. To get the rest later, raise a new\s+order\./,
    );
    assert.match(
        panel,
        /`\/inventory\/purchase-orders\/\$\{row\.publicId\}\/short-close`/,
    );
    assert.match(panel, /min-h-11 items-center gap-2 text-sm lg:min-h-9/);
});

test('each order line says what is outstanding or was not delivered', () => {
    assert.match(
        panel,
        /`Outstanding \$\{lineQuantity\(line\.outstandingQuantity, line\.stockUnit\)\}`/,
    );
    assert.match(
        panel,
        /`Not delivered \$\{lineQuantity\(line\.notDeliveredQuantity, line\.stockUnit\)\}`/,
    );
    assert.match(panel, /Closed short:/);
    assert.match(panel, /Closed short by \{\{ row\.closedBy \}\} on/);
    assert.match(panel, /\? 'Closed short'/);
    assert.match(panel, /partially_received: 'Partially received'/);
    assert.match(
        panel,
        /class="min-h-11 lg:min-h-9"\s+@click="openShortClose\(row\)"/,
    );
});
