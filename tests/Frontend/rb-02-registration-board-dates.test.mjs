import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

// RB-02: Registration/Index.vue used to assign the *filter's* From-To range
// label to every row's arrivedDate, so a multi-day filter showed the same
// two-date range on every row instead of that visit's own registration date.
// This file proves (a) each row now renders its own date, derived from the
// visit's own registeredAtDate, through the real formatDate() implementation,
// and checks the remaining, presentation-only parts of the fix (label moved
// to the filter area, truncate as an overflow safety net) structurally
// against the compiled source, since a headless custom renderer has no real
// layout engine to assert overflow against.
const { createRenderer, defineComponent, h, nextTick } = Vue;

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const presentationSource = read('resources/js/lib/presentation.ts');
const presentationJs = ts.transpileModule(presentationSource, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
    },
}).outputText;
const presentationExports = {};
Function('exports', presentationJs)(presentationExports);

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
            { 'data-testid': 'patient-board' },
            props.rows.map((row) =>
                h('div', {
                    'data-testid': 'board-row',
                    'data-key': row.key,
                    'data-arrived-date': row.arrivedDate,
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
    '@/lib/presentation': { formatDate: presentationExports.formatDate },
    '@/lib/r1c2-presentation': {
        queuePresentationLabel: () => 'Removed',
        waitingDurationLabel: () => 'Waiting',
        servingDurationLabel: () => 'Serving',
    },
};

const BRANCH_TIMEZONE = 'Asia/Kuala_Lumpur';
const makeVisit = (key, registeredAtDate, overrides = {}) => ({
    visitNumber: key,
    patientName: `Patient ${key}`,
    patientNumber: key,
    queueNumber: null,
    registeredAt: '09:15',
    registeredAtDate,
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
                timezone: BRANCH_TIMEZONE,
            },
            doctors: [],
        },
        canCreate: true,
        qrIntakes: null,
    });
    app.mount(mounted.root);

    return mounted;
};

const boardRows = (root) =>
    descendants(root, 'div').filter(
        (node) => node.props['data-testid'] === 'board-row',
    );

test('each row shows its own registration date, spanning a multi-day filter result', async () => {
    const mounted = mountIndex([
        makeVisit('DAY1-VISIT', '2026-09-20'),
        makeVisit('DAY3-VISIT', '2026-09-23'),
    ]);
    await nextTick();

    const rows = boardRows(mounted.root);
    assert.equal(rows.length, 2);
    const byKey = Object.fromEntries(
        rows.map((row) => [row.props['data-key'], row.props['data-arrived-date']]),
    );
    assert.equal(
        byKey['DAY1-VISIT'],
        presentationExports.formatDate('2026-09-20', BRANCH_TIMEZONE),
    );
    assert.equal(
        byKey['DAY3-VISIT'],
        presentationExports.formatDate('2026-09-23', BRANCH_TIMEZONE),
    );
    assert.notEqual(
        byKey['DAY1-VISIT'],
        byKey['DAY3-VISIT'],
        'two visits registered on different days must show different dates',
    );
});

test('a single-day result set still shows the correct, matching date for every row', async () => {
    const mounted = mountIndex([
        makeVisit('A', '2026-09-23'),
        makeVisit('B', '2026-09-23'),
    ]);
    await nextTick();

    const rows = boardRows(mounted.root);
    const expected = presentationExports.formatDate(
        '2026-09-23',
        BRANCH_TIMEZONE,
    );

    for (const row of rows) {
        assert.equal(row.props['data-arrived-date'], expected);
    }
});

test('the filter range label is rendered once, in the results/filter area, not derived per row', () => {
    const source = read('resources/js/pages/Registration/Index.vue');

    // The regression: arrivedDate used to be assigned
    // `registrationDateLabel.value` inside the boardRows mapping. It must
    // now come from the visit's own date instead.
    assert.doesNotMatch(
        source,
        /arrivedDate:\s*registrationDateLabel\.value/,
    );
    assert.match(
        source,
        /arrivedDate:\s*formatDate\(\s*visit\.registeredAtDate/,
    );
    // The label itself must still be rendered somewhere, once, outside the
    // per-row mapping (i.e. in the template, not inside boardRows).
    const boardRowsStart = source.indexOf('const boardRows = computed');
    const boardRowsEnd = source.indexOf(
        ');',
        source.indexOf('rows.value.map'),
    );
    const templateStart = source.indexOf('<template>');
    assert.ok(boardRowsStart > -1 && templateStart > boardRowsEnd);
    assert.doesNotMatch(
        source.slice(boardRowsStart, boardRowsEnd),
        /registrationDateLabel/,
    );
    assert.match(
        source.slice(templateStart),
        /\{\{\s*registrationDateLabel\s*\}\}/,
    );
});

test('the Arrived cell truncates instead of overflowing into Visit Notes', () => {
    const source = read('resources/js/components/patient-board/PatientBoard.vue');
    assert.doesNotMatch(
        source,
        /row\.arrivedDate[\s\S]{0,10}whitespace-nowrap/,
    );
    assert.match(source, /class="block truncate"[^>]*>\{\{\s*\n?\s*row\.arrivedDate/);
});
