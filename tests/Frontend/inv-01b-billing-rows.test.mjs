import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) => ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const money = { exports: {} };
runInNewContext(transpile(read('resources/js/types/billing.ts')), { exports: money.exports });
const { descriptor } = parse(read('resources/js/components/billing/BillingChargeRows.vue'));
const compiled = compileScript(descriptor, { id: 'invoice-rows', inlineTemplate: true });
const component = { exports: {} };
runInNewContext(transpile(compiled.content), {
    exports: component.exports,
    require: (name) => name === '@/types/billing' ? money.exports : require(name),
});
const render = (lines) => renderToString(createSSRApp({ render: () => h(component.exports.default, { lines }) }));

test('billed rows render saved quantities and exact sen amounts without clinical instructions', async () => {
    const html = await render([
        { type: 'medicine', name: 'Synthetic <medicine>', unit: 'tablet', quantity: '2.500', unitPriceSen: 101, totalSen: 253,
            instruction: 'CONFIDENTIAL-INSTRUCTION', indication: 'CONFIDENTIAL-INDICATION', precaution: 'CONFIDENTIAL-PRECAUTION' },
        { type: 'service', name: 'Synthetic service', unit: 'session', quantity: '1.000', unitPriceSen: 2300, totalSen: 2300 },
    ]);
    assert.equal((html.match(/data-testid="billing-charge-row"/g) ?? []).length, 2);
    assert.match(html, /Synthetic &lt;medicine&gt;/);
    assert.match(html, /2\.500 tablet × RM 1\.01/);
    assert.match(html, /RM 2\.53/);
    assert.match(html, /1\.000 session × RM 23\.00/);
    assert.doesNotMatch(html, /CONFIDENTIAL|<input|<button/);
});

test('a line-restricted projection renders the existing empty state and no billed rows', async () => {
    const html = await render([]);
    assert.match(html, /No itemized lines in this authorized view\./);
    assert.doesNotMatch(html, /data-testid="billing-charge-row"|RM /);
});
