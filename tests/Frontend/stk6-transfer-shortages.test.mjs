import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/lib/transfer-receipt.ts');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module },
);
const helpers = module.exports;
const form = read('resources/js/components/inventory/TransferReceiptForm.vue');
const differences = read(
    'resources/js/components/inventory/TransferDifferences.vue',
);
const panel = read(
    'resources/js/components/inventory/InventoryOperationsPanel.vue',
);

test('receipt rows start fully accepted and reconcile exactly in thousandths', () => {
    const row = helpers.defaultTransferReceiptRow('line-1', '10.000');
    assert.equal(row.accepted, '10.000');
    assert.equal(helpers.transferReceiptRowError(row), null);
    assert.equal(helpers.transferReceiptDifferenceCount([row]), 0);

    const partial = {
        ...row,
        accepted: '5.5',
        short: '3.250',
        damaged: '1.25',
        extra: '2',
    };
    assert.equal(helpers.transferReceiptRowError(partial), null);
    assert.equal(helpers.transferReceiptDifferenceCount([partial]), 3);
    assert.deepEqual(
        JSON.parse(JSON.stringify(helpers.transferReceiptPayload([partial]))),
        [
            {
                line_public_id: 'line-1',
                accepted_quantity: '5.5',
                short_quantity: '3.250',
                damaged_quantity: '1.25',
                extra_quantity: '2',
            },
        ],
    );
});

test('unreconciled or imprecise receipt rows are refused before submission', () => {
    const row = helpers.defaultTransferReceiptRow('line-1', '10.000');
    assert.match(
        helpers.transferReceiptRowError({ ...row, accepted: '9.999' }),
        /must equal the dispatched 10\.000/,
    );
    assert.match(
        helpers.transferReceiptRowError({ ...row, accepted: '9.0001' }),
        /three decimal places/,
    );
    assert.match(
        helpers.transferReceiptRowError({ ...row, short: '-1' }),
        /three decimal places/,
    );
    assert.equal(helpers.transferQuantityThousandths('0.1'), 100n);
    assert.equal(helpers.transferQuantityThousandths('1e3'), null);
    assert.equal(helpers.formatTransferThousandths(5500n), '5.500');
});

test('helpers never use binary floating-point parsing', () => {
    assert.doesNotMatch(source, /parseFloat|parseInt|Number\(|toFixed/);
});

test('receipt form and differences use the shared helpers and explain independence', () => {
    assert.match(form, /transferReceiptRowError/);
    assert.match(form, /Only the accepted quantity enters/);
    assert.match(form, /Confirm receipt/);
    assert.match(differences, /type="radio"/);
    assert.match(differences, /You received this transfer/);
    assert.match(differences, /Resolve difference/);
    assert.match(differences, /dark:/);
    assert.doesNotMatch(differences, /<select\b/i);
});

test('panel sends receipt lines and resolutions with fresh retry keys and versions', () => {
    assert.match(panel, /<TransferReceiptForm/);
    assert.match(panel, /<TransferDifferences/);
    assert.match(
        panel,
        /\/inventory\/stock-requests\/\$\{row\.publicId\}\/differences/,
    );
    assert.match(panel, /retry_key: retryKey/);
    assert.match(panel, /receive_idempotency_key: retryKey/);
    assert.match(differences, /retryKey: crypto\.randomUUID\(\)/);
    assert.match(form, /let retryKey = crypto\.randomUUID\(\)/);
    assert.match(panel, /difference_public_id: difference\.publicId/);
    assert.match(panel, /lines,\n\s*\}\);/);
});

test('every outcome has a plain label and reference rules match the server', () => {
    for (const outcome of [
        'lost_in_transit',
        'arrived_later',
        'written_off',
        'accepted_after_inspection',
        'returned_to_source',
        'recorded_by_stock_count',
    ]) {
        assert.ok(helpers.transferOutcomeLabels[outcome], outcome);
    }

    assert.deepEqual(
        JSON.parse(JSON.stringify(helpers.transferOutcomesNeedingReference)),
        [
            'lost_in_transit',
            'arrived_later',
            'written_off',
            'accepted_after_inspection',
            'returned_to_source',
            'recorded_by_stock_count',
        ],
    );
});
