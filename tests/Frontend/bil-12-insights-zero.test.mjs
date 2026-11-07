import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// BIL-12 (Daniel, 2026-11-07): in Gross to net invoice sales a zero reads "RM 0.00" with no sign.
const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const money = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/types/billing.ts'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports: money.exports, require },
);
const component = read(
    'resources/js/components/insights/SalesReconciliation.vue',
);
const signed = (sign, sen) =>
    sen === 0 ? money.exports.myr(0) : `${sign}${money.exports.myr(sen)}`;

test('the three adjustment cells show a plain zero and keep their sign otherwise', () => {
    for (const field of ['surchargesSen', 'discountsSen', 'refundCreditsSen']) {
        assert.match(
            component,
            new RegExp(`signed\\('[+−]', totals\\.${field}\\)`),
        );
    }

    assert.ok(component.includes('sen === 0 ? myr(0) : `${sign}${myr(sen)}`'));
    assert.equal(signed('−', 0), 'RM 0.00');
    assert.equal(signed('−', 1500), '−RM 15.00');
    assert.equal(signed('+', 250), '+RM 2.50');
});
