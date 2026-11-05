import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/supplier-returns.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module, BigInt },
);
const lib = module.exports;
const page = read('resources/js/pages/Inventory/SupplierReturns.vue');
const index = read('resources/js/pages/Inventory/Index.vue');

test('credit amounts convert ringgit to whole sen exactly', () => {
    assert.equal(lib.rmToSen('12'), '1200');
    assert.equal(lib.rmToSen('12.5'), '1250');
    assert.equal(lib.rmToSen(' 0.07 '), '7');
    assert.equal(lib.rmToSen('9999999999.99'), '999999999999');
    assert.equal(lib.rmToSen(''), '');
});

test('text that is not a ringgit amount becomes a marker the server refuses, never reinterpreted digits', () => {
    assert.equal(lib.rmToSen('abc'), 'invalid');
    assert.equal(lib.rmToSen('12345678901'), 'invalid');
    assert.equal(lib.rmToSen('1.234'), 'invalid');
    assert.equal(lib.rmToSen('-5'), 'invalid');
});

test('every server state, reason and history action has a readable label', () => {
    for (const state of [
        'awaiting_approval',
        'approved',
        'rejected',
        'cancelled',
        'dispatched',
        'credited',
    ]) {
        assert.ok(lib.supplierReturnStateLabels[state], state);
    }

    for (const reason of [
        'damaged',
        'short_expiry',
        'quality_issue',
        'recall',
        'wrong_item',
        'overstock_agreed',
        'other_agreed',
    ]) {
        assert.ok(lib.supplierReturnReasonLabels[reason], reason);
    }

    for (const action of [
        'requested',
        'approved',
        'rejected',
        'cancelled',
        'dispatched',
        'credit_recorded',
    ]) {
        assert.ok(lib.supplierReturnHistoryLabels[action], action);
    }
});

test('action buttons follow server flags and each submission carries a retry key and version', () => {
    for (const flag of ['canDecide', 'canDispatch', 'canCredit', 'canCancel']) {
        assert.match(page, new RegExp(`v-if="row\\.${flag}"`));
    }

    assert.match(page, /form\.retry_key = crypto\.randomUUID\(\)/);
    assert.match(page, /form\.lock_version = row\.lock_version/);
    assert.match(page, /credit_amount_sen: rmToSen\(data\.credit_amount_rm\)/);
    assert.match(page, /<InputError :message="errors\.credit_amount_sen" \/>/);
    assert.doesNotMatch(page, /v-html/);
});

test('the Inventory page links to supplier returns', () => {
    assert.match(index, /href="\/inventory\/supplier-returns"/);
});
