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
        { id: 'dsp-doctor-filter', inlineTemplate: true },
    ).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
function mount(canViewReturns = true, selected = 'all') {
    const calls = [];
    const requests = [];
    const stub = (tag = 'div') => ({
        setup:
            (_, { slots, attrs }) =>
            () =>
                vue.h(tag, attrs, slots.default?.()),
    });
    const module = { exports: {} };
    vm.runInNewContext(code, {
        exports: module.exports,
        AbortController,
        URLSearchParams,
        fetch: (url, options) =>
            new Promise((resolve, reject) =>
                requests.push({ url, options, resolve, reject }),
            ),
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
        focus() {
            this.focused = true;
        },
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
        doctorContext: 'branch-one',
        doctorFilter: selected,
        doctorLabel: 'Synthetic Pinned Doctor',
        tab: 'prepare',
        search: 'Synthetic',
        counts: { prepare: 7, check: 3, done: 2 },
        canPrintLabels: false,
        rows: [],
        total: 0,
        page: 4,
        lastPage: 4,
    });
    const state = vue.reactive({ list });
    const root = node('root');
    renderer
        .createApp({
            render: () =>
                vue.h(module.exports.default, {
                    list: state.list,
                    canViewReturns,
                }),
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

    return {
        get list() {
            return state.list;
        },
        replaceList: (next) => {
            state.list = next;
        },
        calls,
        all,
        requests,
    };
}

const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await vue.nextTick();
};
const findForm = (app) =>
    app.all().find((n) => n.props.id === 'dispensary-doctor-search').parent
        .parent;
const submit = (app) => findForm(app).props.onSubmit({ preventDefault() {} });
const response = (app, index, data) =>
    app.requests[index].resolve({ ok: true, json: async () => data });

test('Doctor selection stays server-owned and GET preserves Type and submitted search; leaving Prepare clears it', async () => {
    const app = mount();
    app.list.caseType = 'consultation';
    app.list.doctorFilter = 'opaque-selected';
    app.list.doctorLabel = 'Synthetic Selected Doctor';
    await vue.nextTick();
    const select = app.all().find((n) => n.props.id === 'dispensary-doctor');
    select.props.onChange({ target: { value: 'otc' } });
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls[0][1])), {
        tab: 'prepare',
        page: 1,
        search: 'Synthetic',
        case_type: 'consultation',
        doctor: 'otc',
    });
    assert.equal(select.props.value, 'opaque-selected');
    app.all()
        .find((n) => n.props.label === 'Dispensary cases by status')
        .props['onUpdate:modelValue']('check');
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls[1][1])), {
        tab: 'check',
        page: 1,
        search: 'Synthetic',
    });
    app.list.tab = 'check';
    await vue.nextTick();
    assert.equal(
        app.all().some((n) => n.props.id === 'dispensary-doctor'),
        false,
    );
});

test('Paged choices preserve selected doctor, synchronously focus persistent region, and latest request wins', async () => {
    const app = mount(true, 'pinned');
    app.list.doctorLabel = 'Synthetic Pinned Doctor';
    await vue.nextTick();
    submit(app);
    assert.equal(
        app.all().find((n) => n.props['aria-label'] === 'Doctor filter choices')
            .focused,
        true,
    );
    response(app, 0, {
        data: [{ value: 'other', label: 'Synthetic Other' }],
        page: 1,
        lastPage: 2,
        total: 26,
        search: '',
        reset: false,
    });
    await settle();
    assert.equal(
        app.all().find((n) => n.props.id === 'dispensary-doctor').props.value,
        'pinned',
    );
    app.all()
        .find(
            (n) =>
                n.tag === 'button' &&
                n.children.some((c) => c.text === 'Next doctors'),
        )
        .props.onClick();
    assert.equal(
        new URL(app.requests[1].url, 'http://fixture').searchParams.get('page'),
        '2',
    );
    submit(app);
    assert.equal(app.requests[1].options.signal.aborted, true);
    response(app, 2, {
        data: [{ value: 'latest', label: 'Latest choice' }],
        page: 1,
        lastPage: 1,
        total: 1,
        search: '',
        reset: false,
    });
    await settle();
    response(app, 1, {
        data: [{ value: 'stale', label: 'Stale choice' }],
        page: 2,
        lastPage: 2,
        total: 26,
        search: '',
        reset: false,
    });
    await settle();
    assert.equal(
        app.all().some((n) => n.tag === 'option' && n.props.value === 'stale'),
        false,
    );
    assert.equal(
        app.all().some((n) => n.tag === 'option' && n.props.value === 'latest'),
        true,
    );
    assert.equal(
        app.calls.length,
        0,
        'loading options never changes the case filter',
    );
    submit(app);
    app.requests[3].resolve({ ok: false });
    await settle();
    assert.equal(
        app.all().some((n) => n.tag === 'option' && n.props.value === 'latest'),
        false,
    );
    assert.ok(app.all().some((n) => n.props.role === 'alert'));
});

test('Server stale reset is explicit and malformed option selection never supplies a foreign label', async () => {
    const app = mount();
    app.list.doctorFilter = 'all';
    app.list.doctorLabel = 'All doctors';
    app.list.doctorFilterReset = true;
    await vue.nextTick();
    assert.ok(
        app
            .all()
            .some(
                (n) =>
                    n.props.role === 'status' &&
                    n.text.includes('Showing All doctors'),
            ),
    );
    assert.equal(
        app.all().find((n) => n.props.id === 'dispensary-doctor').props.value,
        'all',
    );
});

test('ordinary list refresh cannot replace a pending doctor search; branch change clears search and rejects its late response', async () => {
    const app = mount();
    const input = () =>
        app.all().find((n) => n.props.id === 'dispensary-doctor-search');
    input().props['onUpdate:modelValue']('new pending search');
    submit(app);
    assert.equal(
        new URL(app.requests[0].url, 'http://fixture').searchParams.get(
            'search',
        ),
        'new pending search',
    );
    app.replaceList({ ...app.list, counts: { prepare: 8, check: 3, done: 2 } });
    await vue.nextTick();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].options.signal.aborted, false);
    app.list.doctorContext = 'branch-two';
    await vue.nextTick();
    assert.equal(app.requests[0].options.signal.aborted, true);
    assert.equal(
        new URL(app.requests[1].url, 'http://fixture').searchParams.get(
            'search',
        ),
        '',
    );
    assert.equal(
        new URL(app.requests[1].url, 'http://fixture').searchParams.get('page'),
        '1',
    );
    assert.equal(input().props.modelValue, '');
    response(app, 1, {
        data: [],
        page: 1,
        lastPage: 1,
        total: 0,
        search: '',
        reset: false,
    });
    response(app, 0, {
        data: [{ value: 'old-branch', label: 'Old branch identity' }],
        page: 1,
        lastPage: 1,
        total: 1,
        search: 'new pending search',
        reset: false,
    });
    await settle();
    assert.equal(
        app
            .all()
            .some((n) => n.tag === 'option' && n.props.value === 'old-branch'),
        false,
    );
});
