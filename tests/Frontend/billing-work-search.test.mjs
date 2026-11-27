import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h, reactive, nextTick } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/pages/FinancialWork/Index.vue',
    'utf8',
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const props = (overrides = {}) => ({
    work: {
        mode: 'finance',
        branch: 'Synthetic branch',
        data: [],
        total: 0,
        currentPage: 1,
        lastPage: 1,
        filters: { q: '' },
        ...overrides,
    },
});
function load(inlineTemplate, errors = {}) {
    const calls = [];
    let key = 0;
    const code = ts.transpileModule(
        compileScript(parse(source).descriptor, {
            id: 'billing-work-search',
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

            if (name === '@/components/ui/state') {
                return {
                    EmptyState: {
                        props: ['title', 'description'],
                        setup: (p) => () => h('div', [p.title, p.description]),
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

test('actual finance and Panel templates show applied search and truthful empty scope', async () => {
    for (const mode of ['finance', 'panel']) {
        const html = await render({
            mode,
            filters: { q: 'Synthetic unmatched' },
        });
        assert.match(html, /aria-label="Search current work"/);
        assert.match(html, /for="work-search"/);
        assert.match(html, /Applied search: “Synthetic unmatched”/);
        assert.match(html, /No matching work/);
        assert.match(
            html,
            /No authorized records match this search in Synthetic branch/,
        );
        assert.match(html, /Clear search/);
        assert.doesNotMatch(html, /Record payment|To pay/);

        if (mode === 'panel') {
            assert.match(html, /Claims submission is not/);
        }
    }

    const html = await render();
    assert.match(html, /No search filter applied/);
    assert.match(html, /No Finance work/);
    assert.doesNotMatch(html, /Clear search/);
});

test('actual GET handlers separate unsent draft from applied pagination and preserve loaded results on refusal', async () => {
    for (const mode of ['finance', 'panel']) {
        const { page, calls } = load(false);
        const input = reactive(
            props({
                mode,
                filters: { q: 'Applied patient' },
                total: 26,
                lastPage: 2,
            }),
        );
        const vm = page.setup(input, { expose() {} });
        vm.query.value = 'Unsent other patient';
        vm.turnPage(2);
        assert.equal(calls.at(-1)[2].q, 'Applied patient');
        assert.equal(calls.at(-1)[2].page, 2);
        assert.equal(
            calls.at(-1)[1],
            mode === 'panel' ? '/panel-responsibility' : '/financial-work',
        );
        vm.search();
        assert.equal(calls.at(-1)[2].q, 'Unsent other patient');
        assert.equal(calls.at(-1)[2].page, 1);
        assert.equal(calls.at(-1)[3].preserveState, true);
        assert.equal(vm.appliedQuery.value, 'Applied patient');
        assert.equal(input.work.total, 26);
        vm.clearSearch();
        assert.equal(calls.at(-1)[2].q, undefined);
        assert.equal(vm.appliedQuery.value, 'Applied patient');
        input.work.filters.q = '';
        await nextTick();
        assert.equal(vm.query.value, '');
        assert.ok(calls.every((c) => c[0] === 'get'));
    }
});

test('actual loaded branch identity and mode replacement reset unsent text to applied criteria', async () => {
    const { page } = load(false);
    const input = reactive(props({ branchId: 3, filters: { q: 'Applied' } }));
    const vm = page.setup(input, { expose() {} });
    vm.query.value = 'Unsent same-context replacement';
    input.work = { ...input.work, filters: { q: 'Applied' } };
    await nextTick();
    assert.equal(vm.query.value, 'Unsent same-context replacement');
    vm.query.value = 'Unsent old branch';
    input.work.branchId = 4;
    await nextTick();
    assert.equal(
        vm.query.value,
        'Applied',
        'same display name does not preserve the old branch draft',
    );
    vm.query.value = 'Unsent finance';
    input.work.mode = 'panel';
    await nextTick();
    assert.equal(vm.query.value, 'Applied');
    assert.equal(vm.panel.value, true);
});

test('out-of-range pages do not claim zero matching records', async () => {
    const html = await render({
        total: 26,
        currentPage: 1000,
        lastPage: 2,
        filters: { q: 'Matching' },
    });
    assert.match(html, /No records on this page/);
    assert.match(html, /Use Search to return to the first page/);
    assert.doesNotMatch(html, /No matching work|No authorized records match/);
});
