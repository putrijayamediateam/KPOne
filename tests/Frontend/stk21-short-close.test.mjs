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
    assert.match(
        panel,
        /shortClose\.reason !== 'other' \|\| shortClose\.note\.trim\(\) !== ''/,
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
});
