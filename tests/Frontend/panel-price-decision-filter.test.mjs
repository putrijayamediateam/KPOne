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
const source = readFileSync(
    'resources/js/pages/PanelPriceChanges/Index.vue',
    'utf8',
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const props = (overrides = {}) => ({
    branch: { id: 3, name: 'Synthetic branch', timezone: 'Asia/Kuala_Lumpur' },
    canPropose: true,
    canApprove: false,
    panels: [],
    charges: [],
    selection: null,
    waiting: [],
    waitingCount: 0,
    decided: [],
    decidedPagination: { page: 1, lastPage: 1, total: 0 },
    decidedStatus: null,
    decidedContext: true,
    rejectReasons: {},
    ...overrides,
});
function load(inlineTemplate, errors = {}) {
    const calls = [];
    let key = 0;
    const code = ts.transpileModule(
        compileScript(parse(source).descriptor, {
            id: 'price-decision-filter',
            inlineTemplate,
        }).content,
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        crypto: {
            randomUUID: () =>
                `00000000-0000-4000-8000-${String(++key).padStart(12, '0')}`,
        },
        document: { getElementById: () => ({ focus: () => {} }) },
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    Link: container,
                    usePage: () => ({ props: { errors } }),
                    router: {
                        get: (...args) => calls.push(['get', ...args]),
                        post: (...args) => calls.push(['post', ...args]),
                    },
                };
            }

            if (name === 'vue-sonner') {
                return { toast: { success: () => {} } };
            }

            if (name === '@/types/billing') {
                return { myr: (sen) => `RM ${(sen / 100).toFixed(2)}` };
            }

            if (name === '@/components/ui/button') {
                return {
                    Button: {
                        props: ['disabled'],
                        setup:
                            (p, { slots, attrs }) =>
                            () =>
                                h(
                                    'button',
                                    { ...attrs, disabled: p.disabled },
                                    slots.default?.(),
                                ),
                    },
                };
            }

            if (name === '@/components/ui/input') {
                return {
                    Input: {
                        props: ['modelValue', 'disabled'],
                        setup:
                            (p, { attrs }) =>
                            () =>
                                h('input', {
                                    ...attrs,
                                    value: p.modelValue,
                                    disabled: p.disabled,
                                }),
                    },
                };
            }

            if (name.startsWith('@/')) {
                return new Proxy(container, {
                    get: (object, field) =>
                        field in object ? object[field] : container,
                });
            }

            return require(name);
        },
    });

    return { page: module.exports.default, calls };
}
async function render(overrides = {}, errors = {}) {
    const { page } = load(true, errors);

    return renderToString(
        createSSRApp({ render: () => h(page, props(overrides)) }),
    );
}

test('actual direct All page1 and filtered template stay Decided with explicit scope', async () => {
    for (const status of [null, 'approved', 'rejected', 'withdrawn']) {
        const html = await render({ decidedStatus: status });
        assert.match(html, /aria-label="Filter price decisions"/);
        assert.match(html, /for="decision-status"/);
        assert.match(html, /Matching decisions across this organisation/);
        assert.match(html, /they do not filter this list/);
        assert.match(html, /All decisions/);
        assert.equal(html.includes('Clear decision filter'), status !== null);
    }

    const readonlyHtml = await render({ canPropose: false, canApprove: true });
    assert.doesNotMatch(readonlyHtml, /Panel and charge selections/);
    const defaultHtml = await render({ decidedContext: false });
    assert.doesNotMatch(defaultHtml, /aria-label="Filter price decisions"/);
});

test('actual filter and page handlers retain loaded status and proposal draft without writing', () => {
    const { page, calls } = load(false);
    const inputs = props({
        decidedStatus: 'approved',
        decidedPagination: { page: 2, lastPage: 2, total: 26 },
    });
    const vm = page.setup(inputs, { expose() {} });
    vm.form.book = '42';
    vm.form.charge = 'charge-x';
    vm.form.price = '12.34';
    vm.form.reference = 'SYN-DRAFT';
    const key = vm.form.key;
    const target = { value: 'rejected' };
    vm.changeDecisionStatus({ target });
    assert.equal(
        target.value,
        'approved',
        'loaded control restored while request pending or failed',
    );
    const request = calls.at(-1);
    assert.equal(request[0], 'get');
    assert.equal(request[2].decided_page, 1);
    assert.equal(request[2].decided_status, 'rejected');
    assert.equal(request[2].panel, '42');
    assert.equal(request[2].charge, 'charge-x');
    assert.equal(request[3].preserveState, true);
    vm.turnPage(2);
    assert.equal(calls.at(-1)[2].decided_status, 'approved');
    vm.loadDecisions(1, '');
    assert.equal(calls.at(-1)[2].decided_page, 1);
    assert.equal(calls.at(-1)[2].decided_status, undefined);
    assert.equal(vm.form.price, '12.34');
    assert.equal(vm.form.reference, 'SYN-DRAFT');
    assert.equal(vm.form.key, key);
    assert.ok(calls.every((c) => c[0] === 'get'));
});

test('selection-only request keeps loaded decision page and filter and direct context', () => {
    const { page, calls } = load(false);
    const vm = page.setup(
        props({
            decidedStatus: 'withdrawn',
            decidedPagination: { page: 2, lastPage: 2, total: 26 },
        }),
        { expose() {} },
    );
    assert.equal(vm.tab.value, 'decided');
    vm.form.book = '42';
    vm.form.charge = 'charge-x';
    vm.loadSelection();
    const request = calls.at(-1);
    assert.equal(request[2].decided_page, 2);
    assert.equal(request[2].decided_status, 'withdrawn');
    assert.equal(request[3].only.join(','), 'selection');
    assert.equal(request[3].preserveState, true);
});
