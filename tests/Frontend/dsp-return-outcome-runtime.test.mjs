import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const source = readFileSync(
    'resources/js/pages/Dispensary/ReturnsToCheck.vue',
    'utf8',
);
const descriptor = parse(source).descriptor;
const calls = [];
const blank = { render: () => null };
const scriptImports = {
    vue: { ...vue, onMounted: () => {} },
    '@inertiajs/vue3': {
        Head: blank,
        router: { get: (...args) => calls.push(args) },
    },
    '@/components/ui/action-link': { ActionLink: blank },
    '@/components/ui/badge': { Badge: blank },
    '@/components/ui/page-header': { PageHeader: blank },
    '@/components/ui/pagination': { CompactPagination: blank },
    '@/components/ui/state': { EmptyState: blank },
    '@/components/ui/status': { StatusBadge: blank },
    '@/components/ui/table': { OperationalTable: blank },
    '@/components/ui/tabs/OperationalTabs.vue': { default: blank },
    '@/lib/dispensary-returns': {
        refuseReasonLabels: {},
        returnConditionLabels: {},
        returnReasonLabels: {},
    },
};
function evaluate(code, imports) {
    const module = { exports: {} };
    vm.runInNewContext(
        ts.transpileModule(code, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            exports: module.exports,
            require: (name) => {
                assert.ok(name in imports, name);

                return imports[name];
            },
        },
    );

    return module.exports;
}
const component = evaluate(
    compileScript(descriptor, { id: 'return-outcome' }).content,
    scriptImports,
).default;
const compiled = compileTemplate({
    source: descriptor.template.content,
    filename: 'ReturnsToCheck.vue',
    id: 'return-outcome',
});
assert.deepEqual(compiled.errors, []);
const template = evaluate(compiled.code, { vue }).render;
const defaults = {
    branchName: 'Synthetic Branch',
    view: 'checked',
    controlled: 'all',
    outcome: 'all',
    monthLabel: 'November 2026',
    counts: { waiting: 2, checked: 0 },
    rows: [],
    page: 1,
    lastPage: 1,
    total: 0,
};
function setup(overrides = {}) {
    const props = vue.reactive({ returns: { ...defaults, ...overrides } });

    return { props, api: component.setup(props, { expose: () => {} }) };
}
async function render(overrides = {}) {
    const { api, props } = setup(overrides);
    const app = vue.createSSRApp({
        setup: () => ({ ...api, returns: props.returns }),
        render: template,
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Actual component name in isolated page renderer.
    app.component('Head', blank);
    app.component('PageHeader', blank);
    app.component('OperationalTabs', blank);

    for (const name of [
        'ActionLink',
        'Badge',
        'StatusBadge',
        'OperationalTable',
        'CompactPagination',
    ]) {
        app.component(name, blank);
    }

    app.component('EmptyState', {
        props: ['title', 'description'],
        setup: (p) => () => vue.h('section', [p.title, p.description]),
    });

    return renderToString(app);
}
test('checked outcome select uses server state, an explicit label and truthful filtered empty copy', async () => {
    const html = await render({ outcome: 'dispose' });
    assert.match(html, /<label for="returns-outcome"/);
    assert.match(html, /<select[^>]*id="returns-outcome"/);
    assert.match(html, /<select[^>]*value="dispose"/);
    assert.match(html, /No matching checked returns/);
    assert.match(html, /Choose All outcomes or All medicines/);
    assert.match(html, /0 matching checked returns/);
    const single = await render({ outcome: 'quarantine', total: 1 });
    assert.match(single, /1 matching checked return</);
    assert.doesNotMatch(single, /1 matching checked returns/);
    const waiting = await render({ view: 'waiting', outcome: 'dispose' });
    assert.doesNotMatch(waiting, /<select|matching checked returns/);
    assert.match(waiting, /No returns waiting/);
});
test('actual GET navigation resets filter changes, preserves checked paging and clears outcome when leaving checked', () => {
    const { api, props } = setup({
        outcome: 'quarantine',
        controlled: 'yes',
        page: 3,
    });
    calls.length = 0;
    api.go({ outcome: 'dispose' });
    assert.equal(calls[0][0], '/dispensary/returns-to-check');
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0][1])), {
        page: 1,
        view: 'checked',
        controlled: 'yes',
        outcome: 'dispose',
    });
    api.visit(2);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[1][1])), {
        page: 2,
        view: 'checked',
        controlled: 'yes',
        outcome: 'quarantine',
    });
    api.go({ controlled: 'no' });
    assert.equal(calls[2][1].outcome, 'quarantine');
    assert.equal(calls[2][1].page, 1);
    api.go({ view: 'waiting' });
    assert.equal(calls[3][1].outcome, undefined);
    assert.equal(calls[3][1].view, undefined);
    props.returns = { ...props.returns, outcome: 'refuse' };
    assert.equal(api.selectedOutcome.value, 'refuse');
    api.go({ outcome: 'all' });
    assert.equal(calls[4][1].outcome, undefined);
});
