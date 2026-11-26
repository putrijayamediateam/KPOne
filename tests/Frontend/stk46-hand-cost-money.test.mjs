import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const source = readFileSync(
    new URL('../../resources/js/lib/hand-cost.ts', import.meta.url),
    'utf8',
);
const js = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.ESNext,
        target: ts.ScriptTarget.ES2020,
    },
}).outputText;
const { handCostPerUnit } = await import(
    `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
);
test('hand cost per-unit echo uses exact half-up arithmetic including free and fractional stock', () => {
    assert.equal(handCostPerUnit(0, 1000), '0.0000');
    assert.equal(handCostPerUnit(1, 1000), '0.0100');
    assert.equal(handCostPerUnit(1, 8000), '0.0013');
    assert.equal(handCostPerUnit(1, 16000), '0.0006');
    assert.equal(handCostPerUnit(1234, 100000), '0.1234');
    assert.equal(handCostPerUnit(999999999999, 1), '9999999999990.0000');
});
test('an invalid or zero quantity cannot display a misleading per-unit cost', () => {
    for (const [sen, quantity] of [
        [1, 0],
        [1, -1],
        [1, 1.5],
        [-1, 1000],
        [0.5, 1000],
        [Number.MAX_SAFE_INTEGER + 1, 1000],
    ]) {
        assert.equal(handCostPerUnit(sen, quantity), null);
    }
});
