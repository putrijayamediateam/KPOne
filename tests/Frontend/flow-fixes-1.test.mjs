import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

// Mount the real Registration page with fake time and controllable browser lifecycle.
const realSetTimeout = globalThis.setTimeout;
const { createRenderer, defineComponent, h, nextTick } = Vue;

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const compileComponent = (path, requireMap = {}) => {
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const componentModule = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === 'vue') {
            return Vue;
        }

        if (specifier in requireMap) {
            return requireMap[specifier];
        }

        throw new Error(`Unexpected component import: ${specifier}`);
    };

    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(localRequire, componentModule, componentModule.exports);

    return componentModule.exports.default;
};

const createMountedRenderer = () => {
    if (!globalThis.Document) {
        globalThis.Document = class {};
    }

    const rootDocument = new globalThis.Document();
    rootDocument.activeElement = null;
    const createElement = (type) => {
        const element = {
            type,
            props: {},
            children: [],
            listeners: {},
            parent: null,
            addEventListener(name, listener) {
                this.listeners[name] = listener;
            },
            removeEventListener(name) {
                delete this.listeners[name];
            },
            getRootNode() {
                return rootDocument;
            },
            focus() {},
        };

        return element;
    };
    const renderer = createRenderer({
        patchProp(element, key, _previous, next) {
            element.props[key] = next;
        },
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const position = anchor ? parent.children.indexOf(anchor) : -1;

            if (position >= 0) {
                parent.children.splice(position, 0, child);
            } else {
                parent.children.push(child);
            }
        },
        remove(child) {
            const position = child.parent?.children.indexOf(child) ?? -1;

            if (position >= 0) {
                child.parent.children.splice(position, 1);
            }
        },
        createElement,
        createText: (text) => ({ type: '#text', text, parent: null }),
        createComment: (text) => ({ type: '#comment', text, parent: null }),
        setText(node, text) {
            node.text = text;
        },
        setElementText(element, text) {
            element.text = text;
            element.children = [];
        },
        parentNode: (node) => node.parent,
        nextSibling(node) {
            const siblings = node.parent?.children ?? [];

            return siblings[siblings.indexOf(node) + 1] ?? null;
        },
        querySelector: () => null,
        setScopeId: () => {},
        cloneNode: (node) => ({ ...node, props: { ...node.props } }),
        insertStaticContent: () => [null, null],
    });

    return { renderer, root: createElement('root') };
};

const descendants = (node, type) => {
    if (Array.isArray(node)) {
        return node.flatMap((child) => descendants(child, type));
    }

    if (!node) {
        return [];
    }

    return [
        ...(node.type === type ? [node] : []),
        ...descendants(node.children ?? [], type),
    ];
};

const NullStub = defineComponent({
    inheritAttrs: false,
    setup: () => () => null,
});
const LinkStub = defineComponent({
    inheritAttrs: false,
    props: { href: String },
    setup:
        (props, { slots }) =>
        () =>
            h('a', { href: props.href }, slots.default?.()),
});
const ButtonStub = defineComponent({
    inheritAttrs: false,
    props: { disabled: Boolean, variant: String, type: String },
    setup:
        (props, { attrs, slots }) =>
        () =>
            h(
                'button',
                { ...attrs, disabled: props.disabled, type: props.type },
                slots.default?.(),
            ),
});
const InputErrorStub = defineComponent({
    props: { message: String },
    setup: (props) => () =>
        h('p', { 'data-testid': 'input-error' }, props.message),
});
const PatientBoardStub = defineComponent({
    inheritAttrs: false,
    props: {
        rows: { type: Array, default: () => [] },
        busyKey: String,
        emptyMessage: String,
    },
    setup:
        (props, { attrs }) =>
        () =>
            h(
                'div',
                {
                    ...attrs,
                    'data-testid': 'patient-board',
                    'data-empty-message': props.emptyMessage ?? '',
                },
                props.rows.map((row) =>
                    h('div', {
                        'data-testid': 'board-row',
                        'data-key': row.key,
                    }),
                ),
            ),
});
const OperationalTabsStub = defineComponent({
    inheritAttrs: false,
    props: { modelValue: String, tabs: Array, label: String },
    emits: ['update:modelValue'],
    setup:
        (props, { emit }) =>
        () =>
            h(
                'div',
                { 'data-testid': 'tabs' },
                (props.tabs ?? []).map((tab) =>
                    h(
                        'button',
                        {
                            'data-tab': tab.value,
                            onClick: () => emit('update:modelValue', tab.value),
                        },
                        tab.label,
                    ),
                ),
            ),
});

const OperationalSelectStub = defineComponent({
    props: { modelValue: [String, Number], label: String, options: Array },
    emits: ['update:modelValue'],
    setup:
        (props, { emit }) =>
        () =>
            h('select', {
                'data-label': props.label,
                onChange: (event) =>
                    emit('update:modelValue', event.target.value),
            }),
});

const requireMap = {
    '@inertiajs/vue3': {
        Head: NullStub,
        Link: LinkStub,
        router: {
            post: (...args) => environment.mutate?.(...args),
            patch: () => {},
            reload: () => {},
        },
        usePage: () => ({ url: '/registration' }),
    },
    '@lucide/vue': {
        ChevronDown: NullStub,
        LoaderCircle: NullStub,
        Plus: NullStub,
        Search: NullStub,
        SlidersHorizontal: NullStub,
        X: NullStub,
    },
    '@/components/InputError.vue': { default: InputErrorStub },
    '@/components/patient-board/PatientBoard.vue': {
        default: PatientBoardStub,
    },
    '@/components/patient-board/VisitCancellationDialog.vue': {
        default: NullStub,
    },
    '@/components/registration/QrIntakeTable.vue': { default: NullStub },
    '@/components/ui/button': { Button: ButtonStub },
    '@/components/ui/select': { OperationalSelect: OperationalSelectStub },
    '@/components/ui/tabs/OperationalTabs.vue': {
        default: OperationalTabsStub,
    },
    '@/lib/presentation': { formatDate: (value) => String(value) },
    '@/lib/r1c2-presentation': {
        queuePresentationLabel: () => 'Removed',
        waitingDurationLabel: () => 'Waiting',
        servingDurationLabel: () => 'Serving',
    },
};

const makeVisit = (key, overrides = {}) => ({
    visitNumber: key,
    patientName: `Patient ${key}`,
    patientNumber: key,
    queueNumber: null,
    registeredAt: '09:15',
    registeredAtDate: '2026-09-23',
    visitReasonExcerpt: null,
    doctorName: null,
    coverageLabel: 'Self-pay',
    priority: 'normal',
    status: 'registered',
    queueStatus: null,
    isHeld: false,
    durationMinutes: null,
    visitLockVersion: 1,
    queueLockVersion: null,
    can: {},
    ...overrides,
});

const mountIndex = (initialRows, overrides = {}) => {
    const Index = compileComponent(
        'resources/js/pages/Registration/Index.vue',
        requireMap,
    );
    const mounted = createMountedRenderer();
    const app = mounted.renderer.createApp(Index, {
        visits: {
            data: initialRows,
            total: initialRows.length,
            currentPage: overrides.page ?? 1,
            lastPage: overrides.lastPage ?? 1,
        },
        options: {
            branch: {
                id: 1,
                code: 'CHERAS',
                name: 'Cheras',
                timezone: 'Asia/Kuala_Lumpur',
            },
            doctors: overrides.doctors ?? [],
        },
        canCreate: true,
        qrIntakes: overrides.qrIntakes ?? null,
    });
    app.mount(mounted.root);

    return { ...mounted, app };
};

const tabButton = (root, value) =>
    descendants(root, 'button').find(
        (button) => button.props['data-tab'] === value,
    );

const board = (root) =>
    descendants(root, 'div').find(
        (node) => node.props['data-testid'] === 'patient-board',
    );
const boardRowKeys = (root) =>
    descendants(root, 'div')
        .filter((node) => node.props['data-testid'] === 'board-row')
        .map((node) => node.props['data-key']);

const flush = async () => {
    // Drain the microtask queue (fetch/json promise chains, however many
    // hops deep) before asking Vue's own scheduler to flush reactive
    // updates. A fixed number of nextTick() calls is not reliable here
    // because resolving a deferred fetch can take several microtask hops
    // before it reaches rows.value.
    await new Promise((resolve) => realSetTimeout(resolve, 0));
    await nextTick();
    await nextTick();
};

const deferred = () => {
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });

    return { promise, resolve };
};
const jsonResponse = (status, payload) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
});

const saved = {};
let environment;
const mountedApps = [];
const mount = (options = {}) => {
    const mounted = mountIndex([makeVisit('ORIGINAL')], options);
    mountedApps.push(mounted.app);

    return mounted;
};
const content = (node) =>
    [node.text ?? '', ...(node.children ?? []).map(content)].join('');
const button = (root, label) =>
    descendants(root, 'button').find((node) => content(node).trim() === label);
const submit = (root) =>
    descendants(root, 'form')[0].props.onSubmit({
        preventDefault() {},
        stopPropagation() {},
    });
const response = (key = 'ARRIVAL', page = 1) =>
    jsonResponse(200, {
        data: [makeVisit(key)],
        total: 7,
        currentPage: page,
        lastPage: 3,
    });

test.beforeEach(() => {
    Object.assign(saved, {
        document: globalThis.document,
        window: globalThis.window,
        fetch: globalThis.fetch,
        setTimeout: globalThis.setTimeout,
        clearTimeout: globalThis.clearTimeout,
        error: console.error,
    });
    const timers = new Map();
    const listeners = new Map();
    let time = 0;
    let id = 0;
    environment = { timers, listeners, overlay: null, requests: [] };
    globalThis.setTimeout = (callback, delay) => {
        const key = ++id;
        timers.set(key, { callback, at: time + delay });

        return key;
    };
    globalThis.clearTimeout = (key) => timers.delete(key);
    environment.advance = async (delay) => {
        const until = time + delay;

        for (;;) {
            const next = [...timers].sort((a, b) => a[1].at - b[1].at)[0];

            if (!next || next[1].at > until) {
                break;
            }

            time = next[1].at;
            timers.delete(next[0]);
            next[1].callback();
            await flush();
        }

        time = until;
    };
    environment.respond = async () => response();
    globalThis.fetch = async (url, options) => {
        environment.requests.push({
            url,
            options,
            body: JSON.parse(options.body),
        });

        return environment.respond(environment.requests.length);
    };
    globalThis.document = {
        hidden: false,
        activeElement: null,
        querySelector: (selector) =>
            selector.includes('meta[')
                ? { content: 'synthetic-csrf' }
                : environment.overlay &&
                    selector.includes(`[role="${environment.overlay}"]`)
                  ? {}
                  : null,
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name, callback) => {
            if (listeners.get(name) === callback) {
                listeners.delete(name);
            }
        },
    };
    globalThis.window = {
        history: { replaceState() {} },
        location: { search: '' },
    };
    console.error = () => {};
});
test.afterEach(() => {
    for (const app of mountedApps.splice(0)) {
        app.unmount();
    }

    Object.assign(globalThis, {
        document: saved.document,
        window: saved.window,
        fetch: saved.fetch,
        setTimeout: saved.setTimeout,
        clearTimeout: saved.clearTimeout,
    });
    console.error = saved.error;
});

const appliedPageTwo = async () => {
    const mounted = mount({ lastPage: 3 });
    descendants(mounted.root, 'input')[0].props['onUpdate:modelValue'](
        'Synthetic Filter',
    );
    tabButton(mounted.root, 'waiting').props.onClick();
    await flush();
    environment.respond = async () => response('FILTERED-PAGE-TWO', 2);
    button(mounted.root, 'Next').props.onClick();
    await flush();

    return mounted;
};

test('FLOW-FIXES-1 readiness success re-fetches the applied tab, filter and page without applying a draft', async () => {
    const mounted = await appliedPageTwo();
    const before = { ...environment.requests.at(-1).body };
    descendants(mounted.root, 'input')[0].props['onUpdate:modelValue'](
        'Unsubmitted draft',
    );
    board(mounted.root).props.onReadinessChanged();
    await flush();
    assert.equal(environment.requests.length, 3);
    assert.deepEqual(environment.requests.at(-1).body, before);
    assert.deepEqual(boardRowKeys(mounted.root), ['FILTERED-PAGE-TWO']);
});

test('FLOW-FIXES-1 readiness refresh keeps menu and in-flight stale-response protections', async () => {
    const mounted = await appliedPageTwo();
    environment.overlay = 'dialog';
    board(mounted.root).props.onReadinessChanged();
    await flush();
    assert.equal(environment.requests.length, 2);
    environment.overlay = null;
    const pending = deferred();
    environment.respond = () => pending.promise;
    board(mounted.root).props.onReadinessChanged();
    await flush();
    descendants(mounted.root, 'input')[0].props['onUpdate:modelValue'](
        'New Search',
    );
    environment.respond = async () => response('NEWEST', 1);
    submit(mounted.root);
    await flush();
    pending.resolve(response('STALE', 2));
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), ['NEWEST']);
});

test('FLOW-FIXES-1 Send to Waiting preserves state and refreshes only after a successful Registration mutation', async () => {
    const mounted = await appliedPageTwo();
    let mutation;
    environment.mutate = (url, data, options) => {
        mutation = { url, data, options };
    };
    const row = {
        key: 'SYN',
        visitNumber: 'KPV-00000001',
        source: { visitLockVersion: 1 },
    };
    board(mounted.root).props.onSendToWaiting(row);
    assert.equal(mutation.data.from, 'registration');
    assert.equal(mutation.options.preserveState, true);
    mutation.options.onFinish();
    await flush();
    assert.equal(environment.requests.length, 2);
    board(mounted.root).props.onSendToWaiting(row);
    mutation.options.onSuccess();
    mutation.options.onFinish();
    await flush();
    assert.equal(environment.requests.length, 3);
    assert.equal(environment.requests.at(-1).body.page, 2);
    assert.equal(
        environment.requests.at(-1).body.patient_query,
        'Synthetic Filter',
    );
});

test('FLOW-FIXES-1 readiness component preserves Registration state and emits success after releasing its busy state', () => {
    const source = read(
        'resources/js/components/queue/CallReadinessActions.vue',
    );
    assert.match(source, /preserveState: props.from === 'registration'/);
    assert.match(
        source,
        /onFinish:[\s\S]*busy.value = false;[\s\S]*if \(succeeded\)[\s\S]*emit\('changed'\)/,
    );
    assert.match(
        read('resources/js/components/patient-board/PatientBoard.vue'),
        /@changed="\$emit\('readinessChanged'\)"/,
    );
    assert.match(
        read('resources/js/pages/Registration/Show.vue'),
        /const queueForm = useForm\(\{\s*from: 'registration'/,
    );
});

const clinicalComponent = (checkout, patches) => {
    const path = 'resources/js/pages/Clinical/Show.vue';
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: 'flow-clinical',
        inlineTemplate: false,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const componentModule = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === 'vue') {
            return Vue;
        }

        if (specifier === '@inertiajs/vue3') {
            return {
                usePage: () => ({
                    props: {
                        auth: {
                            user: { name: 'Synthetic Doctor' },
                            permissions: [],
                        },
                    },
                }),
                useForm: (data) =>
                    Vue.reactive({
                        ...data,
                        errors: {},
                        isDirty: false,
                        processing: false,
                        patch: (...args) => patches.push(args),
                        clearErrors() {},
                        defaults() {},
                    }),
            };
        }

        return new Proxy(
            { default: NullStub },
            {
                get: (target, key) =>
                    key in target ? target[key] : () => null,
            },
        );
    };
    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(localRequire, componentModule, componentModule.exports);
    const component = componentModule.exports.default;
    component.render = () => null;
    const mounted = createMountedRenderer();
    const clinical = {
        branch: { id: 1, timezone: 'Asia/Kuala_Lumpur' },
        patient: { name: 'Synthetic Patient' },
        visit: { visitNumber: 'KPV-00000001' },
        queue: { queuedAt: new Date().toISOString() },
        encounter: { lockVersion: 1, clinicalNote: 'Synthetic note' },
        hold: { isHeld: false },
        vitals: {},
        diagnoses: [],
        treatmentPlan: { checkout },
        dispensaryAttention: [],
    };
    const app = mounted.renderer.createApp(component, { clinical });
    app.mount(mounted.root);
    mountedApps.push(app);

    return app._instance.setupState;
};

test('FLOW-FIXES-1 current checkout marks the consultation completed and guards submit without changing stored status', () => {
    const patches = [];
    const state = clinicalComponent(
        { route: 'dispensary', lockVersion: 1, canReopen: false },
        patches,
    );
    assert.equal(state.consultationCompleted, true);
    state.save();
    assert.equal(patches.length, 0);
    const source = read('resources/js/pages/Clinical/Show.vue');
    assert.match(source, /consultationCompleted\s*\? 'Completed'/);
    assert.match(
        source,
        /:disabled="\s*form.processing \|\|\s*clinical.hold.isHeld \|\|\s*consultationCompleted/,
    );
    assert.match(
        source,
        /Completed means the consultation was handed off, not that\s+payment is finished\./,
    );
});

test('FLOW-FIXES-1 a consultation without checkout retains its existing Save request', () => {
    const patches = [];
    const state = clinicalComponent(null, patches);
    assert.equal(state.consultationCompleted, false);
    state.save();
    assert.equal(patches.length, 1);
    assert.equal(patches[0][0], '/visits/KPV-00000001/encounter');
});
