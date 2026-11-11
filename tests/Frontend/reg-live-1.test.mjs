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
    setup: (props) => () =>
        h(
            'div',
            {
                'data-testid': 'patient-board',
                'data-empty-message': props.emptyMessage ?? '',
            },
            props.rows.map((row) =>
                h('div', { 'data-testid': 'board-row', 'data-key': row.key }),
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
        router: { post: () => {}, patch: () => {}, reload: () => {} },
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
    '@/components/ui/date-field': { DateField: NullStub },
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

test('REG-LIVE-1 polls at ten seconds with the existing request and no overlap', async () => {
    const mounted = mount({ page: 2, lastPage: 3 });
    assert.equal(environment.timers.size, 1);
    await environment.advance(9999);
    assert.equal(environment.requests.length, 0);
    await environment.advance(1);
    assert.equal(environment.requests.length, 1);
    assert.deepEqual(boardRowKeys(mounted.root), ['ARRIVAL']);
    const request = environment.requests[0];
    assert.equal(request.url, '/registration/search');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
    assert.equal(request.body.page, 2);
    assert.equal(request.body.board_status, 'all');
    await environment.advance(50000);
    assert.equal(environment.requests.length, 6);
});

test('REG-LIVE-1 preserves applied search, filters, tab and page without applying unfinished edits', async () => {
    const mounted = mount({
        lastPage: 3,
        doctors: [{ id: 11, name: 'Synthetic doctor' }],
    });
    const field = descendants(mounted.root, 'input')[0];
    field.props['onUpdate:modelValue']('Synthetic Filter');
    descendants(mounted.root, 'select')
        .find((n) => n.props['data-label'] === 'Doctor')
        .props.onChange({ target: { value: '11' } });
    tabButton(mounted.root, 'waiting').props.onClick();
    await flush();
    environment.respond = async () => response('PAGE-TWO', 2);
    button(mounted.root, 'Next').props.onClick();
    await flush();
    field.props['onUpdate:modelValue']('Unapplied text');
    await environment.advance(10000);
    const body = environment.requests.at(-1).body;
    assert.equal(environment.requests.length, 3);
    assert.equal(body.patient_query, 'Synthetic Filter');
    assert.equal(body.doctor_id, '11');
    assert.equal(body.board_status, 'waiting');
    assert.equal(body.page, 2);
    assert.equal(body.date_from, body.date_to);
    assert.deepEqual(Object.keys(body).sort(), [
        'board_status',
        'coverage_type',
        'date_from',
        'date_to',
        'doctor_id',
        'page',
        'patient_query',
        'priority',
        'visit_type',
    ]);
});

test('REG-LIVE-1 hidden tab aborts and pauses, visible tab refreshes once and rejects the hidden response', async () => {
    const first = deferred();
    environment.respond = async (call) =>
        call === 1 ? first.promise : response('VISIBLE');
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 1);
    document.hidden = true;
    environment.listeners.get('visibilitychange')();
    assert.equal(environment.requests[0].options.signal.aborted, true);
    assert.equal(environment.timers.size, 0);
    await environment.advance(60000);
    assert.equal(environment.requests.length, 1);
    document.hidden = false;
    environment.listeners.get('visibilitychange')();
    await flush();
    assert.equal(environment.requests.length, 2);
    first.resolve(response('HIDDEN-STALE'));
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), ['VISIBLE']);
});

test('REG-LIVE-1 does not request or replace rows while a menu, dialog or editing field is active', async () => {
    for (const kind of ['menu', 'dialog', 'field']) {
        const mounted = mount();
        environment.overlay = kind === 'field' ? null : kind;
        document.activeElement =
            kind === 'field' ? { matches: () => true } : null;
        const before = environment.requests.length;
        await environment.advance(30000);
        assert.equal(environment.requests.length, before, kind);
        assert.deepEqual(boardRowKeys(mounted.root), ['ORIGINAL']);
        environment.overlay = null;
        document.activeElement = null;
        await environment.advance(10000);
        assert.equal(environment.requests.length, before + 1, kind);
        mounted.app.unmount();
        mountedApps.pop();
    }
});

test('REG-LIVE-1 checks protection again when an in-flight success or failure arrives', async () => {
    for (const kind of ['menu', 'dialog', 'field']) {
for (const status of [200, 500]) {
            const pending = deferred();
            environment.respond = async () => pending.promise;
            const mounted = mount();
            const before = environment.requests.length;
            await environment.advance(10000);
            assert.equal(environment.requests.length, before + 1);
            environment.overlay = kind === 'field' ? null : kind;
            document.activeElement =
                kind === 'field' ? { matches: () => true } : null;
            pending.resolve(
                status === 200
                    ? response('NEW')
                    : jsonResponse(500, { message: 'Synthetic failure' }),
            );
            await flush();
            assert.deepEqual(
                boardRowKeys(mounted.root),
                ['ORIGINAL'],
                `${kind}/${status}`,
            );
            assert.equal(board(mounted.root).props['data-empty-message'], '');
            environment.overlay = null;
            document.activeElement = null;
            mounted.app.unmount();
            mountedApps.pop();
        }
}
});

test('REG-LIVE-1 stale automatic success and failure cannot overwrite the latest manual response', async () => {
    for (const staleStatus of [200, 500]) {
        const old = deferred();
        let calls = 0;
        environment.respond = async () =>
            ++calls === 1 ? old.promise : response('LATEST');
        const mounted = mount();
        await environment.advance(10000);
        assert.equal(calls, 1);
        tabButton(mounted.root, 'billing').props.onClick();
        await flush();
        assert.deepEqual(boardRowKeys(mounted.root), ['LATEST']);
        old.resolve(
            staleStatus === 200
                ? response('STALE')
                : jsonResponse(500, { message: 'Stale failure' }),
        );
        await flush();
        assert.deepEqual(boardRowKeys(mounted.root), ['LATEST']);
        assert.equal(board(mounted.root).props['data-empty-message'], '');
        mounted.app.unmount();
        mountedApps.pop();
    }
});

test('REG-LIVE-1 errors back off for twenty then forty seconds, stop at three, and manual Apply recovers', async () => {
    environment.respond = async () =>
        jsonResponse(500, {
            message: 'Registration search could not be completed.',
        });
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 1);
    assert.deepEqual(boardRowKeys(mounted.root), ['ORIGINAL']);
    await environment.advance(19999);
    assert.equal(environment.requests.length, 1);
    await environment.advance(1);
    assert.equal(environment.requests.length, 2);
    await environment.advance(39999);
    assert.equal(environment.requests.length, 2);
    await environment.advance(1);
    assert.equal(environment.requests.length, 3);
    assert.equal(environment.timers.size, 0);
    assert.equal(
        board(mounted.root).props['data-empty-message'],
        '',
    );
    assert.equal(liveNotice(mounted.root), pausedNotice + ' Press Search to try again.');
    document.hidden = true;
    environment.listeners.get('visibilitychange')();
    document.hidden = false;
    environment.listeners.get('visibilitychange')();
    await flush();
    await environment.advance(300000);
    assert.equal(environment.requests.length, 3);
    environment.respond = async () => response('RECOVERED');
    submit(mounted.root);
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), ['RECOVERED']);
    await environment.advance(10000);
    assert.equal(environment.requests.length, 5);
});

test('REG-LIVE-1 unmount removes the timer/listener and aborts before a late response', async () => {
    const pending = deferred();
    environment.respond = async () => pending.promise;
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 1);
    mounted.app.unmount();
    mountedApps.pop();
    assert.equal(environment.requests[0].options.signal.aborted, true);
    assert.equal(environment.timers.size, 0);
    assert.equal(environment.listeners.size, 0);
    pending.resolve(response('TOO-LATE'));
    await flush();
    await environment.advance(60000);
    assert.equal(environment.requests.length, 1);
    assert.equal(environment.timers.size, 0);
});

test('REG-LIVE-1 pauses visit polling on the separate QR Intake tab and resumes on an operational tab', async () => {
    const mounted = mount({
        qrIntakes: {
            pendingCount: 0,
            branch: { id: 1 },
            items: [],
            history: { total: 0, currentPage: 1, lastPage: 1 },
        },
    });
    tabButton(mounted.root, 'qr-intake').props.onClick();
    await environment.advance(30000);
    assert.equal(environment.requests.length, 0);
    tabButton(mounted.root, 'all').props.onClick();
    await flush();
    assert.equal(environment.requests.length, 1);
    await environment.advance(10000);
    assert.equal(environment.requests.length, 2);
});

test('REG-LIVE-1 a slow request cannot overlap a scheduled poll', async () => {
    const pending = deferred();
    environment.respond = async () => pending.promise;
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 1);
    await environment.advance(60000);
    assert.equal(environment.requests.length, 1);
    pending.resolve(response());
    await flush();
    await environment.advance(9999);
    assert.equal(environment.requests.length, 1);
    await environment.advance(1);
    assert.equal(environment.requests.length, 2);
    assert.deepEqual(boardRowKeys(mounted.root), ['ARRIVAL']);
});

const pausedNotice = 'Live refresh paused. Showing last successful results.';
const liveNotice = (root) => {
    const notice = descendants(root, 'p').find((node) => node.props.role === 'status');

    return notice ? content(notice).trim() : '';
};
const searchField = (root) => descendants(root, 'input')[0];
const focusBoardSearch = () => {
    const element = { id: 'registration-board-search', matches: () => true };
    document.activeElement = element;

    return element;
};

test('REG-LIVE-1 Enter submission leaves search focused and permits polling the applied value', async () => {
    const mounted = mount();
    const field = searchField(mounted.root);
    field.props['onUpdate:modelValue']('Synthetic');
    const focused = focusBoardSearch();
    submit(mounted.root);
    await flush();
    assert.equal(document.activeElement, focused);
    assert.equal(environment.requests.length, 1);
    await environment.advance(10000);
    assert.equal(environment.requests.length, 2);
    assert.equal(environment.requests[1].body.patient_query, 'Synthetic');
    assert.equal(document.activeElement, focused);
});

test('REG-LIVE-1 typing after submission pauses until that draft is submitted without requiring blur', async () => {
    const mounted = mount();
    const field = searchField(mounted.root);
    const focused = focusBoardSearch();
    submit(mounted.root);
    await flush();
    field.props['onUpdate:modelValue']('Unsubmitted');
    await environment.advance(30000);
    assert.equal(environment.requests.length, 1);
    submit(mounted.root);
    await flush();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 3);
    assert.equal(environment.requests.at(-1).body.patient_query, 'Unsubmitted');
    assert.equal(document.activeElement, focused);
});

test('REG-LIVE-1 an in-flight response cannot replace rows or show a failure notice while search becomes dirty', async () => {
    for (const status of [200, 500]) {
        const pending = deferred();
        environment.respond = async () => pending.promise;
        const mounted = mount();
        focusBoardSearch();
        await environment.advance(10000);
        const before = environment.requests.length;
        assert.ok(before > 0);
        searchField(mounted.root).props['onUpdate:modelValue']('Draft');
        pending.resolve(status === 200 ? response('IGNORED') : jsonResponse(500, {}));
        await flush();
        assert.deepEqual(boardRowKeys(mounted.root), ['ORIGINAL']);
        assert.equal(liveNotice(mounted.root), '');
        mounted.app.unmount();mountedApps.pop();
    }
});

test('REG-LIVE-1 IME composition protects an unchanged search value until composition ends', async () => {
    const mounted = mount();
    focusBoardSearch();
    const field = searchField(mounted.root);
    field.props.onCompositionstart();
    await environment.advance(20000);
    assert.equal(environment.requests.length, 0);
    field.props.onCompositionend();
    await environment.advance(10000);
    assert.equal(environment.requests.length, 1);
});

test('REG-LIVE-1 IME composition also protects against an already in-flight response', async () => {
    const pending = deferred();
    environment.respond = async () => pending.promise;
    const mounted = mount();
    focusBoardSearch();
    await environment.advance(10000);
    searchField(mounted.root).props.onCompositionstart();
    pending.resolve(response('IGNORED'));
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), ['ORIGINAL']);
    searchField(mounted.root).props.onCompositionend();
    environment.respond = async () => response('UPRIGHT');
    await environment.advance(10000);
    assert.deepEqual(boardRowKeys(mounted.root), ['UPRIGHT']);
});

test('REG-LIVE-1 automatic HTTP and network failures retain rows total and both page controls', async () => {
    for (const mode of ['http', 'network']) {
        environment.respond = async () => {
            if (mode === 'network') {
throw new Error('Synthetic connection failure');
}

            return jsonResponse(500, {});
        };
        const mounted = mount({page: 2, lastPage: 3});
        await environment.advance(10000);
        assert.deepEqual(boardRowKeys(mounted.root), ['ORIGINAL']);
        assert.match(content(mounted.root), /1 Visit/);
        assert.equal(button(mounted.root, 'Previous').props.disabled, false);
        assert.equal(button(mounted.root, 'Next').props.disabled, false);
        assert.equal(environment.requests.at(-1).body.page, 2);
        assert.equal(board(mounted.root).props['data-empty-message'], '');
        assert.equal(liveNotice(mounted.root), pausedNotice);
        environment.respond = async () => response('RECOVERED', 3);
        button(mounted.root, 'Next').props.onClick();
        await flush();
        assert.equal(environment.requests.at(-1).body.page, 3);
        assert.equal(button(mounted.root, 'Next').props.disabled, true);
        assert.equal(liveNotice(mounted.root), '');
        mounted.app.unmount();mountedApps.pop();
    }
});

test('REG-LIVE-1 a successful automatic retry clears the pause notice and resets the cadence', async () => {
    environment.respond = async () => jsonResponse(500, {});
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(liveNotice(mounted.root), pausedNotice);
    environment.respond = async () => response('RECOVERED');
    await environment.advance(20000);
    assert.deepEqual(boardRowKeys(mounted.root), ['RECOVERED']);
    assert.equal(liveNotice(mounted.root), '');
    await environment.advance(10000);
    assert.equal(environment.requests.length, 3);
});

test('REG-LIVE-1 starting a manual search clears the pause notice and manual network failure still clears the board', async () => {
    environment.respond = async () => jsonResponse(500, {});
    const mounted = mount();
    await environment.advance(10000);
    assert.equal(liveNotice(mounted.root), pausedNotice);
    const pending = deferred();
    environment.respond = async () => {
 await pending.promise;

 throw new Error('Synthetic manual failure');
};
    submit(mounted.root);
    await flush();
    assert.equal(liveNotice(mounted.root), '');
    pending.resolve();
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), []);
    assert.equal(board(mounted.root).props['data-empty-message'], 'Registration search could not be completed.');
    assert.equal(liveNotice(mounted.root), '');
});

test('REG-LIVE-1 a stale automatic failure cannot restore a notice after a newer manual success', async () => {
    const pending = deferred();
    let calls = 0;
    environment.respond = async () => ++calls === 1 ? pending.promise : response('LATEST');
    const mounted = mount();
    await environment.advance(10000);
    submit(mounted.root);
    await flush();
    pending.resolve(jsonResponse(500, {}));
    await flush();
    assert.deepEqual(boardRowKeys(mounted.root), ['LATEST']);
    assert.equal(liveNotice(mounted.root), '');
});
