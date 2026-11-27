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
    compileScript(descriptor, { id: 'return-age' }).content,
    scriptImports,
).default;
const compiled = compileTemplate({
    source: descriptor.template.content,
    filename: 'ReturnsToCheck.vue',
    id: 'return-age',
});
assert.deepEqual(compiled.errors, []);
const template = evaluate(compiled.code, { vue }).render;
const defaults = {
    branchName: 'Synthetic Branch',
    view: 'waiting',
    controlled: 'all',
    outcome: 'all',
    waitingAge: 'all',
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
test('waiting age uses normalized server state, explicit exact-day labels and truthful empty/reset help', async () => {
    const html = await render({ waitingAge: '3days' });
    assert.match(html, /<label for="returns-waiting-age"/);
    assert.match(html, /<select[^>]*id="returns-waiting-age"/);
    assert.match(html, /<select[^>]*value="3days"/);
    assert.match(html, /2 days ago/);
    assert.match(html, /3 days ago/);
    assert.match(html, /No matching waiting returns/);
    assert.match(html, /All ages or All medicines/);
    assert.match(html, /All includes older returns/);
    assert.match(html, /0 matching waiting returns/);
    const single = await render({ waitingAge: 'today', total: 1 });
    assert.match(single, /1 matching waiting return</);
    const checked = await render({ view: 'checked', waitingAge: '3days' });
    assert.doesNotMatch(
        checked,
        /id="returns-waiting-age"|matching waiting returns/,
    );
    assert.match(checked, /id="returns-outcome"/);
});

test('actual age GET resets page, preserves controlled/paging and clears age on checked navigation', () => {
    const { api, props } = setup({
        waitingAge: '3days',
        controlled: 'yes',
        page: 3,
    });
    calls.length = 0;
    api.go({ waitingAge: 'today' });
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0][1])), {
        page: 1,
        controlled: 'yes',
        waiting_age: 'today',
    });
    assert.equal(api.selectedWaitingAge.value, '3days');
    api.visit(2);
    assert.equal(calls[1][1].waiting_age, '3days');
    assert.equal(calls[1][1].page, 2);
    api.go({ controlled: 'no' });
    assert.equal(calls[2][1].waiting_age, '3days');
    assert.equal(calls[2][1].page, 1);
    api.go({ view: 'checked' });
    assert.equal(calls[3][1].waiting_age, undefined);
    props.returns = { ...props.returns, view: 'checked' };
    api.go({ view: 'waiting' });
    assert.equal(calls[4][1].waiting_age, undefined);
    props.returns = {
        ...props.returns,
        view: 'waiting',
        waitingAge: 'yesterday',
    };
    assert.equal(api.selectedWaitingAge.value, 'yesterday');
    api.go({ waitingAge: 'all' });
    assert.equal(calls[5][1].waiting_age, undefined);
});
