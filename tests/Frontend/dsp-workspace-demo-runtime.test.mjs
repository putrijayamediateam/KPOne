import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const require = createRequire(import.meta.url);
const code = ts.transpileModule(
    compileScript(
        parse(readFileSync('resources/js/pages/Dispensary/Index.vue', 'utf8'))
            .descriptor,
        { id: 'dsp-workspace', inlineTemplate: true },
    ).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
function mount(permissions = ['dispensary.view.branch']) {
    const calls = [];
    const stub = (tag = 'div') => ({
        setup:
            (_, { slots, attrs }) =>
            () =>
                vue.h(tag, attrs, slots.default?.()),
    });
    const module = { exports: {} };
    vm.runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === 'vue') {
                return {
                    ...vue,
                    onMounted: () => {},
                    onBeforeUnmount: () => {},
                };
            }

            if (name === '@inertiajs/vue3') {
                return {
                    Head: stub(),
                    Link: stub('a'),
                    usePage: () => ({ props: { auth: { permissions } } }),
                    router: { get: (...args) => calls.push(args) },
                };
            }

            if (name.startsWith('@/components/') || name === '@lucide/vue') {
                return new Proxy(
                    {},
                    {
                        get: (_, key) =>
                            key === '__esModule'
                                ? true
                                : stub(key === 'Button' ? 'button' : 'div'),
                    },
                );
            }

            if (name === '@/lib/waiting-time') {
                return {
                    waitingTone: () => 'normal',
                    waitingToneDescription: () => '',
                };
            }

            return require(name);
        },
    });
    const node = (tag, text = '') => ({
        tag,
        text,
        props: {},
        children: [],
        parent: null,
    });
    const renderer = vue.createRenderer({
        createElement: node,
        createText: (text) => node('#text', text),
        createComment: (text) => node('#comment', text),
        setText: (n, t) => (n.text = t),
        setElementText: (n, t) => {
            n.text = t;
            n.children = [];
        },
        patchProp: (n, k, _old, v) => (n.props[k] = v),
        parentNode: (n) => n.parent,
        nextSibling: (n) =>
            n.parent?.children[n.parent.children.indexOf(n) + 1] ?? null,
        insert: (n, p, anchor) => {
            if (n.parent) {
                n.parent.children.splice(n.parent.children.indexOf(n), 1);
            }

            n.parent = p;
            const i = anchor ? p.children.indexOf(anchor) : -1;
            p.children.splice(i < 0 ? p.children.length : i, 0, n);
        },
        remove: (n) => {
            if (n.parent) {
                n.parent.children.splice(n.parent.children.indexOf(n), 1);
            }
        },
    });
    const list = vue.reactive({
        branchName: 'Synthetic Branch',
        tab: 'prepare',
        search: 'Synthetic',
        counts: { prepare: 7, check: 3, done: 2 },
        canPrintLabels: false,
        rows: [],
        total: 0,
        page: 4,
        lastPage: 4,
    });
    const root = node('root');
    renderer
        .createApp({ render: () => vue.h(module.exports.default, { list }) })
        .mount(root);
    const all = () => {
        const out = [];
        const walk = (n) => {
            out.push(n);
            n.children.forEach(walk);
        };
        walk(root);

        return out;
    };

    return { list, calls, all };
}

test('workspace cards use fresh server counts and existing tab GET preserving search and resetting page', async () => {
    const app = mount();
    const card = () =>
        app
            .all()
            .find(
                (n) =>
                    n.props['aria-label'] === "Waiting for doctor's check: 3",
            );
    assert.equal(card().props['aria-pressed'], false);
    card().props.onClick();
    assert.equal(app.calls[0][0], '/dispensary');
    assert.equal(app.calls[0][1].tab, 'check');
    assert.equal(app.calls[0][1].page, 1);
    assert.equal(app.calls[0][1].search, 'Synthetic');
    app.list.tab = 'check';
    app.list.counts.check = 9;
    await vue.nextTick();
    const updated = app
        .all()
        .find((n) => n.props['aria-label'] === "Waiting for doctor's check: 9");
    assert.equal(updated.props['aria-pressed'], true);
});

test('readonly branch viewer receives no Returns navigation; branch viewer uses existing protected route', () => {
    assert.equal(
        mount([])
            .all()
            .some((n) => n.props.href === '/dispensary/returns-to-check'),
        false,
    );
    assert.equal(
        mount()
            .all()
            .filter((n) => n.props.href === '/dispensary/returns-to-check')
            .length,
        1,
    );
});

test('help toggle removes the entire aside and restores it without issuing navigation', async () => {
    const app = mount();
    const toggle = () =>
        app
            .all()
            .find(
                (n) =>
                    n.tag === 'button' &&
                    n.props['aria-controls'] === 'dispensary-help',
            );
    assert.ok(app.all().some((n) => n.tag === 'aside'));
    toggle().props.onClick();
    await vue.nextTick();
    assert.equal(toggle().props['aria-expanded'], false);
    assert.equal(
        app.all().some((n) => n.tag === 'aside'),
        false,
    );
    toggle().props.onClick();
    await vue.nextTick();
    assert.ok(app.all().some((n) => n.tag === 'aside'));
    assert.equal(app.calls.length, 0);
});
