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
        { id: 'dsp-type-filter', inlineTemplate: true },
    ).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
function mount(canViewReturns = true) {
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
        .createApp({
            render: () =>
                vue.h(module.exports.default, { list, canViewReturns }),
        })
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

test('Prepare Type uses normalized selection and GET preserves submitted search while resetting page', async () => {
    const app = mount();
    const select = () =>
        app.all().find((n) => n.props.id === 'dispensary-case-type');
    assert.equal(select().props.value, 'all');
    assert.ok(
        app
            .all()
            .some(
                (n) =>
                    n.tag === 'label' && n.props.for === 'dispensary-case-type',
            ),
    );
    select().props.onChange({ target: { value: 'otc' } });
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls[0][1])), {
        tab: 'prepare',
        page: 1,
        search: 'Synthetic',
        case_type: 'otc',
    });
    assert.equal(select().props.value, 'all', 'server response owns selection');
    app.list.caseType = 'otc';
    await vue.nextTick();
    assert.equal(select().props.value, 'otc');
    assert.ok(
        app
            .all()
            .some((n) => n.props.description?.includes('Choose All cases')),
    );
    select().props.onChange({ target: { value: 'all' } });
    assert.equal(app.calls[1][1].case_type, undefined);
});

test('leaving Prepare clears Type and Waiting/Done cannot render or preserve a crafted selection', async () => {
    const app = mount();
    app.list.caseType = 'consultation';
    await vue.nextTick();
    app.all()
        .find((n) => n.props['aria-label'] === "Waiting for doctor's check: 3")
        .props.onClick();
    assert.equal(app.calls[0][1].case_type, undefined);
    assert.equal(app.calls[0][1].page, 1);

    for (const tab of ['check', 'done']) {
        app.list.tab = tab;
        await vue.nextTick();
        assert.equal(
            app.all().some((n) => n.props.id === 'dispensary-case-type'),
            false,
        );
        app.all()
            .find((n) => n.props['aria-label'] === 'To prepare: 7')
            .props.onClick();
        assert.equal(app.calls.at(-1)[1].case_type, undefined);
        assert.equal(app.calls.at(-1)[1].tab, 'prepare');
    }
});
