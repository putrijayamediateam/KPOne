import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-11 (design debt DD-31): below 1024 px the Panel Claims list shows one
// card per claim or invoice instead of a table cut to three columns.

const index = readFileSync(
    new URL('../../resources/js/pages/PanelClaims/Index.vue', import.meta.url),
    'utf8',
);

const block = (testid) => {
    const start = index.indexOf(`data-testid="${testid}"`);
    assert.notEqual(start, -1, `${testid} is missing`);
    const end = index.indexOf('<OperationalTable', start);
    assert.notEqual(end, -1);

    return index.slice(start, end);
};

test('Both tabs show cards below lg and keep the table from lg', () => {
    for (const testid of ['invoice-cards', 'claim-cards']) {
        const start = index.lastIndexOf('<div', index.indexOf(testid));
        assert.match(index.slice(start, start + 200), /lg:hidden/);
    }

    const hiddenTables = index.match(
        /<OperationalTable\s+v-if="work\.(eligible|claims)\.data\.length > 0"\s+class="hidden lg:block"/g,
    );
    assert.equal(hiddenTables?.length, 2);
    // The Columns menu only changes the table, so it is hidden with it.
    assert.match(
        index,
        /class="hidden lg:inline-flex"\s+aria-label="Customize columns"/,
    );
});

test('A claim card carries the same selection rules, actions and fields as its table row', () => {
    const cards = block('claim-cards');

    assert.match(cards, /id="pack-select-all-phone"/);
    assert.match(cards, /togglePackAll\(!packAllSelected\)/);
    assert.match(cards, /Select all \$\{packAllPanel\} claims on this page/);
    assert.match(
        cards,
        /Filter by one Panel to select all claims on this page/,
    );
    assert.match(cards, /!canPack\(row\) \|\|\s*\(atPackMax &&/);
    assert.match(cards, /:title="packLockedReason\(row\)"/);
    assert.match(cards, /'pack-panel-rule'/);
    assert.match(cards, /Select claim \$\{row\.claimNo\} for a claim pack/);
    assert.match(cards, /More actions for claim \$\{row\.claimNo\}/);
    assert.match(cards, />View claim</);
    assert.match(cards, /canMarkSubmitted\(row\.status\)/);

    for (const field of [
        'myr(row.claimAmountSen)',
        'row.panelName',
        'row.patientName',
        'row.visitNumber',
        'row.invoiceNumber',
        'row.createdAt',
        'Updated by {{ row.updatedBy }}',
        'Remarks: {{ row.remarks }}',
    ]) {
        assert.ok(cards.includes(field), `claim card shows ${field}`);
    }
});

test('An invoice card carries the bulk-claim rules, actions and fields of its table row', () => {
    const cards = block('invoice-cards');

    assert.match(cards, /toggleAll\(!allSelected\)/);
    assert.match(
        cards,
        /Select all \$\{selectAllPanel\} invoices on this page/,
    );
    assert.match(cards, /!canSelect\(row\) \|\|\s*\(atBulkMax &&/);
    assert.match(cards, /:title="lockedReason\(row\)"/);
    assert.match(
        cards,
        /Select invoice \$\{row\.invoiceNumber\} for a bulk claim/,
    );
    assert.match(cards, /More actions for invoice \$\{row\.invoiceNumber\}/);
    assert.match(cards, />View invoice</);
    assert.match(cards, /createClaim\(row\.allocationId\)/);
    // Only overdue invoices carry a badge on a card; Pending is the tab itself.
    assert.match(cards, /<StatusBadge\s+v-if="row\.overdue"/);
    assert.doesNotMatch(cards, /label="Pending"/);

    for (const field of [
        'myr(row.amountSen)',
        'row.panelName',
        'row.patientName',
        'visitTypeLabel(row.visitType)',
        'Visit {{ row.visitDate }}',
        'Due {{ row.dueDate',
        'row.doctorName',
        'Remark note: {{ row.remarkNote }}',
    ]) {
        assert.ok(cards.includes(field), `invoice card shows ${field}`);
    }
});

test('Cards meet the phone rules: 44 px targets, wrapping text and no sideways scroll', () => {
    for (const testid of ['invoice-cards', 'claim-cards']) {
        const cards = block(testid);
        assert.match(cards, /min-h-11 min-w-11/);
        assert.match(cards, /wrap-anywhere/);
        assert.match(cards, /grid min-w-0/);
        assert.doesNotMatch(cards, /min-width|overflow-x/);
    }
});

test('Clear puts focus back on whichever Select all is visible', () => {
    assert.match(
        index,
        /\['pack-select-all', 'pack-select-all-phone'\][\s\S]{0,400}\(selectAll \?\? firstRow\)\?\.focus\(\)/,
    );
});
