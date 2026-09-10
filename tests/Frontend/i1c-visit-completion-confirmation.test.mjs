import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const { createRenderer, defineComponent, h, nextTick, reactive } = Vue;
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const compileComponent = (path, requireMap) => {
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const loaded = { exports: {} };
    Function(
        'require',
        'module',
        'exports',
        transpile(compiled),
    )(
        (specifier) => {
            if (specifier === 'vue') {
                return Vue;
            }

            if (specifier in requireMap) {
                return requireMap[specifier];
            }

            throw new Error(`Unexpected import: ${specifier}`);
        },
        loaded,
        loaded.exports,
    );

    return loaded.exports.default;
};
const mountedRenderer = () => {
    const element = (type) => ({ type, props: {}, children: [], parent: null });
    const renderer = createRenderer({
        patchProp(node, key, _previous, next) {
            node.props[key] = next;
        },
        insert(child, parent) {
            child.parent = parent;
            parent.children.push(child);
        },
        remove(child) {
            const index = child.parent?.children.indexOf(child) ?? -1;

            if (index >= 0) {
                child.parent.children.splice(index, 1);
            }
        },
        createElement: element,
        createText: (text) => ({ type: 'text', text, parent: null }),
        createComment: (text) => ({ type: 'comment', text, parent: null }),
        setText(node, text) {
            node.text = text;
        },
        setElementText(node, text) {
            node.children = [{ type: 'text', text, parent: node }];
        },
        parentNode: (node) => node.parent,
        nextSibling: (node) => {
            const index = node.parent?.children.indexOf(node) ?? -1;

            return index >= 0 ? node.parent.children[index + 1] : null;
        },
        querySelector: () => null,
        setScopeId() {},
        insertStaticContent(content, parent) {
            const node = { type: 'static', text: content, parent };
            parent.children.push(node);

            return [node, node];
        },
    });
    const root = element('root');

    return { renderer, root };
};
const descendants = (node, type) => {
    const found = node.type === type ? [node] : [];

    return [
        ...found,
        ...(node.children ?? []).flatMap((child) => descendants(child, type)),
    ];
};
const renderedText = (node) =>
    [node.text ?? '', ...(node.children ?? []).map(renderedText)].join(' ');
const passthrough = (tag) =>
    defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h(tag, slots.default?.()),
    });
const dialogStub = defineComponent({
    props: {
        open: Boolean,
        title: String,
        description: String,
        confirmLabel: String,
        processing: Boolean,
        error: String,
    },
    emits: ['update:open', 'confirm'],
    setup:
        (props, { emit }) =>
        () =>
            h('section', { 'data-open': props.open, role: 'dialog' }, [
                h('h2', props.title),
                h('p', props.description),
                h(
                    'button',
                    {
                        'data-action': 'cancel',
                        disabled: props.processing,
                        onClick: () => emit('update:open', false),
                    },
                    'Cancel',
                ),
                h(
                    'button',
                    {
                        'data-action': 'confirm',
                        disabled: props.processing,
                        onClick: () => emit('confirm'),
                    },
                    props.confirmLabel,
                ),
            ]),
});

const mountDialog = () => {
    const calls = [];
    const updates = [];
    const state = reactive({
        open: true,
        visitNumber: 'KPV-SYNTHETIC-A',
        branchId: 12,
        visitLockVersion: 4,
        invoiceLockVersion: 7,
    });
    const Component = compileComponent(
        'resources/js/components/billing/BillingCompletionDialog.vue',
        {
            '@inertiajs/vue3': {
                router: { post: (...args) => calls.push(args) },
            },
            '@lucide/vue': { CircleCheckBig: passthrough('svg') },
            '@/components/ui/OperationalConfirmDialog.vue': {
                default: dialogStub,
            },
        },
    );
    const mounted = mountedRenderer();
    mounted.renderer
        .createApp(
            defineComponent({
                setup: () => () =>
                    h(Component, {
                        ...state,
                        'onUpdate:open': (value) => updates.push(value),
                    }),
            }),
        )
        .mount(mounted.root);

    return { ...mounted, calls, state, updates };
};
const action = (mounted, name) =>
    descendants(mounted.root, 'button').find(
        (button) => button.props['data-action'] === name,
    );

test('completion dialog identifies the current Visit dynamically and preserves consequence wording', async () => {
    const mounted = mountDialog();
    await nextTick();
    assert.match(
        renderedText(mounted.root),
        /Complete Visitation KPV-SYNTHETIC-A\?/,
    );
    assert.match(
        renderedText(mounted.root),
        /preserves the finalized financial evidence/,
    );
    assert.match(renderedText(mounted.root), /closes ordinary Visit editing/);

    mounted.state.visitNumber = 'KPV-SYNTHETIC-B';
    await nextTick();
    assert.match(
        renderedText(mounted.root),
        /Complete Visitation KPV-SYNTHETIC-B\?/,
    );
    assert.doesNotMatch(renderedText(mounted.root), /KPV-SYNTHETIC-A/);
});

test('cancellation closes the dialog without a completion request', async () => {
    const mounted = mountDialog();
    await nextTick();
    action(mounted, 'cancel').props.onClick();
    assert.deepEqual(mounted.calls, []);
    assert.deepEqual(mounted.updates, [false]);
});

test('confirmation binds the displayed Visit and submits once while processing', async () => {
    const mounted = mountDialog();
    mounted.state.visitNumber = 'KPV-SYNTHETIC-B';
    mounted.state.visitLockVersion = 9;
    mounted.state.invoiceLockVersion = 11;
    await nextTick();
    const confirm = action(mounted, 'confirm');
    confirm.props.onClick();
    confirm.props.onClick();

    assert.equal(mounted.calls.length, 1);
    assert.equal(
        mounted.calls[0][0],
        '/visits/KPV-SYNTHETIC-B/billing/complete',
    );
    assert.deepEqual(mounted.calls[0][1], {
        expected_branch_id: 12,
        lock_version: 11,
        visit_lock_version: 9,
    });
    assert.equal(mounted.calls[0][2].preserveScroll, true);
});

test('Billing uses the shared dialog and does not retain a native confirmation path', () => {
    const billing = read('resources/js/pages/Billing/Show.vue');
    assert.match(billing, /<BillingCompletionDialog/);
    assert.match(billing, /:visit-number="billing\.visit\.visitNumber"/);
    assert.doesNotMatch(billing, /window\.confirm|\bconfirm\s*\(/);
});
