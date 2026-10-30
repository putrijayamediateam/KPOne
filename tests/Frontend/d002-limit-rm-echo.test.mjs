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
const source = read('resources/js/pages/BillingApprovalLimits/Index.vue');
const transpile = (code) => ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const money = { exports: {} };
runInNewContext(transpile(read('resources/js/types/billing.ts')), { exports: money.exports, require });
const module = { exports: {} };
const posts = [];
const compile = compileScript(parse(source).descriptor, { id: 'd002-echo', inlineTemplate: true }).content;
const stub = { render() {
 return h('div', this.$slots.default?.());
} };
runInNewContext(transpile(compile), {
    exports: module.exports,
    require: (name) => {
        if (name === '@inertiajs/vue3') {
return { router: { post: (...args) => posts.push(args) } };
}

        if (name === '@/types/billing') {
return money.exports;
}

        if (name.startsWith('@/components/')) {
            return { default: stub, Button: stub, Input: stub, PageHeader: stub };
        }

        return require(name);
    },
});
const propsFor = (value) => ({
    branch: { id: 7, name: 'Synthetic Echo Branch' },
    adjustmentThreshold: { configured: false, limitSen: null, lockVersion: 0 },
    approvers: [{ id: 17, name: 'Synthetic Approver', email: 'approver@echo.test', canPanelApprove: true, canDefermentApprove: true, panelLimitSen: value, defermentLimitSen: value }],
});
const render = (props) => renderToString(createSSRApp({ render: () => h(module.exports.default, props) }));

for (const [label, value, expected] of [
    ['ordinary sen', 5000, '= RM 50.00'],
    ['zero', 0, '= RM 0.00'],
    ['one sen', 1, '= RM 0.01'],
    ['large maximum', 999999999999, '= RM 9,999,999,999.99'],
]) {
    test(`D002 both sen inputs echo ${label} without modifying props or posting`, async () => {
        const props = propsFor(value);
        const before = structuredClone(props);
        const html = await render(props);
        assert.equal(html.split(expected).length - 1, 2);
        assert.deepEqual(props, before);
        assert.equal(posts.length, 0);

        for (const capability of ['panel', 'deferment']) {
            assert.ok(html.includes(`aria-describedby="limit-echo-17-${capability}"`));
            assert.ok(html.includes(`id="limit-echo-17-${capability}"`));
        }
    });
}

test('D002 empty and invalid sen have no misleading RM helper', async () => {
    for (const value of [null, '', ' ', '-1', '1.5', '1e3', 'NaN', 'Infinity', 'abc', '5,000', '1000000000000', '9007199254740993']) {
        const html = await render(propsFor(value));
        assert.doesNotMatch(html, /= RM|RM NaN|RM Infinity/);
    }
});

test('D002 saved sen, routes, numeric constraints and permission visibility stay pinned', async () => {
    assert.match(source, /limit_sen: formValues\[key\]/);
    assert.match(source, /capability,\s*limit_sen:/);
    assert.match(source, /'\/billing-approval-limits',/);
    assert.match(source, /'\/billing-approval-limits\/clear',/);
    assert.equal((source.match(/type="number"\s+min="0"\s+max="999999999999"/g) ?? []).length, 2);
    assert.equal((source.match(/v-model="\s*formValues\[/g) ?? []).length, 2);
    const props = propsFor(5000);
    props.approvers[0].canPanelApprove = false;
    props.approvers[0].canDefermentApprove = false;
    const html = await render(props);
    assert.doesNotMatch(html, /= RM/);
    assert.match(html, /No Panel approval permission/);
    assert.match(html, /No Pay later approval permission/);
});
