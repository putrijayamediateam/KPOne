import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync(
    new URL('../../resources/js/types/billing.ts', import.meta.url),
    'utf8',
);
const javascript = ts.transpileModule(source.replaceAll('export ', ''), {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const { toSen, myr, outstandingSen } = runInNewContext(
    `${javascript}\n({ toSen, myr, outstandingSen })`,
);

test('money text becomes exact integer sen without floating arithmetic', () => {
    for (const [text, sen] of [
        ['0', '0'],
        ['0.01', '1'],
        ['45.50', '4550'],
        ['0.29', '29'],
        ['9999999999.99', '999999999999'],
    ]) {
        assert.equal(toSen(text), sen);
    }

    assert.equal(myr(4550), 'RM 45.50');
});

test('malformed, negative, exponent, excess precision and overflow stay invalid', () => {
    for (const text of [
        '',
        '-1',
        '1e3',
        '1.001',
        '01',
        '1,000',
        '10000000000',
        'NaN',
    ]) {
        assert.equal(toSen(text), null);
    }
});

test('outstanding presentation combines current due and governed deferred receivable without changing either value', () => {
    const state = {
        total: 10000,
        self_pay: 2500,
        panel: 1500,
        deferred: 4000,
        due_now: 2000,
    };

    assert.equal(outstandingSen(state), 6000);
    assert.equal(state.deferred, 4000);
    assert.equal(state.due_now, 2000);
});
