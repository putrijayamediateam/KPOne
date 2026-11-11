import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/SupplierReturns.vue');

test('several lots from one delivery are sent for approval in one request', () => {
    assert.match(page, /Return several lots from one delivery/);
    assert.match(page, /\.post\('\/inventory\/supplier-return-groups'/);
    assert.match(page, /goods_receipt_id: Number\(selectedDelivery\.value\)/);
    assert.match(page, /chosenCount > returns\.maxGroupLines/);
});

test('a multi-lot return acts on all its lots together', () => {
    assert.match(
        page,
        /endpoint\.value = `\/inventory\/supplier-return-groups\/\$\{group\.id\}\/\$\{action\}`/,
    );

    for (const label of [
        'Approve all lots',
        'Reject all lots',
        'Dispatch all lots',
    ]) {
        assert.match(page, new RegExp(label));
    }

    assert.match(page, /moved\s+together/);
});

test('a refused lot shows its error on the box, and group decisions list the lots', () => {
    assert.match(page, /border-destructive ring-1 ring-destructive/);
    assert.match(page, /:aria-describedby="\s*lotError\(lot\.id\)/);
    assert.match(page, /:id="`lot-error-\$\{lot\.id\}`"/);
    assert.match(page, /scrollIntoView\(\{ block: 'center' \}\)/);
    assert.match(page, /data-testid="group-confirm-lots"/);
    assert.match(page, /Total: \{\{ groupTotals\.join\(' \+ '\) \}\}/);
});

test('reject starts with no reason, and group dispatch warns it cannot be undone', () => {
    assert.match(page, /reason_code: '',/);
    assert.match(
        page,
        /<option value="" disabled>\s*Choose a reason\s*<\/option>/,
    );
    assert.match(page, /Pick a reason to continue\./);
    assert.match(page, /operation === 'rejected' && form\.reason_code === ''/);
    assert.match(page, /It can't be undone\./);
    assert.match(page, /`Dispatch all \$\{lots\} lots\?`/);
});
