import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

// RB-01: Registration/Index.vue's search() used to leave the previous tab's
// rows on screen when a search request failed or was superseded, so switching
// to Dispensary/Awaiting Billing could show Serving Now's patients. This file
// mounts the real component (compiled from its .vue source) with a mocked
// fetch, so the assertions exercise the actual search()/generation-guard
// control flow rather than a regex over the source text.
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
    setup: (props) => () => h('p', { 'data-testid': 'input-error' }, props.message),
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
    '@/components/patient-board/PatientBoard.vue': { default: PatientBoardStub },
    '@/components/patient-board/VisitCancellationDialog.vue': {
        default: NullStub,
    },
    '@/components/registration/QrIntakeTable.vue': { default: NullStub },
    '@/components/ui/button': { Button: ButtonStub },
    '@/components/ui/select': { OperationalSelect: NullStub },
    '@/components/ui/tabs/OperationalTabs.vue': { default: OperationalTabsStub },
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

const mountIndex = (initialRows) => {
    const Index = compileComponent(
        'resources/js/pages/Registration/Index.vue',
        requireMap,
    );
    const mounted = createMountedRenderer();
    const app = mounted.renderer.createApp(Index, {
        visits: {
            data: initialRows,
            total: initialRows.length,
            currentPage: 1,
            lastPage: 1,
        },
        options: {
            branch: {
                id: 1,
                code: 'CHERAS',
                name: 'Cheras',
                timezone: 'Asia/Kuala_Lumpur',
            },
            doctors: [],
        },
        canCreate: true,
        qrIntakes: null,
    });
    app.mount(mounted.root);

    return mounted;
};

const tabButton = (root, value) =>
    descendants(root, 'button').find((button) => button.props['data-tab'] === value);

const board = (root) => descendants(root, 'div').find(
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
    await new Promise((resolve) => setTimeout(resolve, 0));
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

test.beforeEach(() => {
    globalThis.document = {
        querySelector: () => ({ content: 'test-csrf-token' }),
    };
    globalThis.window = {
        history: { replaceState: () => {} },
        location: { search: '' },
    };
});

test('a failed search clears the previous tab\'s rows and surfaces the error in place of the table', async () => {
    const originalFetch = globalThis.fetch;
    const originalError = console.error;
    const errors = [];
    console.error = (...args) => errors.push(args);

    try {
        const mounted = mountIndex([makeVisit('SERVING-1')]);
        await nextTick();
        assert.deepEqual(boardRowKeys(mounted.root), ['SERVING-1']);

        globalThis.fetch = async () =>
            jsonResponse(500, { message: 'Something went wrong on the server.' });

        tabButton(mounted.root, 'dispensary').props.onClick();
        await flush();

        assert.deepEqual(
            boardRowKeys(mounted.root),
            [],
            'Dispensary must not keep Serving Now rows after a failed search',
        );
        assert.equal(
            board(mounted.root).props['data-empty-message'],
            'Something went wrong on the server.',
        );
        assert.ok(
            errors.length > 0,
            'the failing status must be logged for diagnosability',
        );
    } finally {
        globalThis.fetch = originalFetch;
        console.error = originalError;
    }
});

test('a thrown/aborted request clears the board the same way as an HTTP failure', async () => {
    const originalFetch = globalThis.fetch;
    const originalError = console.error;
    console.error = () => {};

    try {
        const mounted = mountIndex([makeVisit('SERVING-1')]);
        await nextTick();

        globalThis.fetch = async () => {
            throw new Error('network down');
        };

        tabButton(mounted.root, 'billing').props.onClick();
        await flush();

        assert.deepEqual(boardRowKeys(mounted.root), []);
        assert.equal(
            board(mounted.root).props['data-empty-message'],
            'Registration search could not be completed.',
        );
    } finally {
        globalThis.fetch = originalFetch;
        console.error = originalError;
    }
});

test('a superseded (stale) response never overwrites a newer result, whether it succeeds or fails', async () => {
    const originalFetch = globalThis.fetch;
    const originalError = console.error;
    console.error = () => {};

    try {
        const mounted = mountIndex([makeVisit('SERVING-1')]);
        await nextTick();

        const first = deferred();
        const second = deferred();
        let calls = 0;
        globalThis.fetch = async () => {
            calls += 1;

            return calls === 1 ? first.promise : second.promise;
        };

        // Two tab switches in quick succession: the first (stale) request is
        // still in flight when the second (current) one is fired.
        tabButton(mounted.root, 'dispensary').props.onClick();
        tabButton(mounted.root, 'billing').props.onClick();

        second.resolve(
            jsonResponse(200, {
                data: [makeVisit('BILLING-1'), makeVisit('BILLING-2')],
                total: 2,
                currentPage: 1,
                lastPage: 1,
            }),
        );
        await flush();
        assert.deepEqual(boardRowKeys(mounted.root), ['BILLING-1', 'BILLING-2']);

        // The stale first request now resolves successfully, after the
        // newer one already rendered. It must be discarded, not applied.
        first.resolve(
            jsonResponse(200, {
                data: [makeVisit('DISPENSARY-1')],
                total: 1,
                currentPage: 1,
                lastPage: 1,
            }),
        );
        await flush();
        assert.deepEqual(
            boardRowKeys(mounted.root),
            ['BILLING-1', 'BILLING-2'],
            'a stale successful response must not clobber the current result',
        );
    } finally {
        globalThis.fetch = originalFetch;
        console.error = originalError;
    }
});

test('a superseded (stale) failure never clears a newer successful result', async () => {
    const originalFetch = globalThis.fetch;
    const originalError = console.error;
    console.error = () => {};

    try {
        const mounted = mountIndex([makeVisit('SERVING-1')]);
        await nextTick();

        const first = deferred();
        const second = deferred();
        let calls = 0;
        globalThis.fetch = async () => {
            calls += 1;

            return calls === 1 ? first.promise : second.promise;
        };

        tabButton(mounted.root, 'dispensary').props.onClick();
        tabButton(mounted.root, 'billing').props.onClick();

        second.resolve(
            jsonResponse(200, {
                data: [makeVisit('BILLING-1')],
                total: 1,
                currentPage: 1,
                lastPage: 1,
            }),
        );
        await flush();
        assert.deepEqual(boardRowKeys(mounted.root), ['BILLING-1']);

        // The stale first request now fails. It must not clear the
        // already-rendered, current result.
        first.resolve(jsonResponse(500, { message: 'stale failure' }));
        await flush();
        assert.deepEqual(
            boardRowKeys(mounted.root),
            ['BILLING-1'],
            'a stale failure must not clear the current successful result',
        );
        assert.equal(board(mounted.root).props['data-empty-message'], '');
    } finally {
        globalThis.fetch = originalFetch;
        console.error = originalError;
    }
});
