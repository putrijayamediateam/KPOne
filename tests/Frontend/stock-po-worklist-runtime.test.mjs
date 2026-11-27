import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, reactive, ref } from 'vue';

const require = createRequire(import.meta.url);
const browserDocument = { activeElement: null };
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const evaluate = (source, resolve = require) => {
    const output = { exports: {} };
    runInNewContext(
        ts.transpileModule(source, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            exports: output.exports,
            require: resolve,
            document: browserDocument,
        },
    );

    return output.exports;
};
const component = evaluate(
    compileScript(
        parse(
            read(
                'resources/js/components/inventory/StockPurchaseOrderList.vue',
            ),
        ).descriptor,
        { id: 'stock-test', inlineTemplate: true },
    ).content,
    (name) => {
        if (name === '@lucide/vue') {
            return { ChevronRight: { render: () => h('i') } };
        }

        if (name === '@/components/ui/input') {
            return {
                Input: {
                    props: ['modelValue', 'disabled'],
                    setup:
                        (props, { attrs, emit }) =>
                        () =>
                            h('input', {
                                ...attrs,
                                disabled: props.disabled,
                                value: props.modelValue,
                                onInput: (event) =>
                                    emit(
                                        'update:modelValue',
                                        event.target.value,
                                    ),
                            }),
                },
            };
        }

        if (name === '@/components/ui/status') {
            return {
                StatusBadge: {
                    props: ['label', 'status'],
                    setup: (props) => () =>
                        h('span', props.label ?? props.status),
                },
            };
        }

        return require(name);
    },
).default;
// Native DOM elements are not reactive; the custom renderer preserves that identity.
const node = (type, text = '') =>
    markRaw({
        type,
        text,
        props: {},
        children: [],
        parent: null,
        style: {},
        addEventListener() {},
        removeEventListener() {},
        get options() {
            return this.children.filter((c) => c.type === 'option');
        },
        get value() {
            return this.props.value;
        },
        get dataset() {
            return {
                poRow: this.props['data-po-row'],
                poTrigger: this.props['data-po-trigger'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.poRow ? this : (this.parent?.closest() ?? null);
        },
        get isConnected() {
            return this.parent
                ? this.parent.children.includes(this) && this.parent.isConnected
                : this.type === 'root';
        },
        getClientRects() {
            return this.isConnected &&
                this.style.display !== 'none' &&
                (!this.parent || this.parent.getClientRects().length)
                ? [{}]
                : [];
        },
        querySelectorAll() {
            return all(this).filter(
                (item) => item.type === 'button' && item.dataset.poTrigger,
            );
        },
    });
const renderer = createRenderer({
    createElement: (type) => node(type),
    createText: (text) => node('#text', text),
    createComment: (text) => node('#comment', text),
    setText: (target, text) => (target.text = text),
    setElementText: (target, text) => {
        target.text = text;
        target.children = [];
    },
    parentNode: (target) => target.parent,
    nextSibling: (target) =>
        target.parent?.children[target.parent.children.indexOf(target) + 1],
    insert: (child, parent, anchor) => {
        if (child.parent) {
            child.parent.children.splice(
                child.parent.children.indexOf(child),
                1,
            );
        }

        child.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;

        if (index < 0) {
            parent.children.push(child);
        } else {
            parent.children.splice(index, 0, child);
        }
    },
    remove: (child) =>
        child.parent?.children.splice(child.parent.children.indexOf(child), 1),
    patchProp: (target, key, previous, value) => (target.props[key] = value),
});
const all = (root) => [root, ...root.children.flatMap(all)];
const text = (root) =>
    all(root)
        .filter((item) => item.type !== '#comment')
        .map((item) => item.text)
        .join(' ');
const line = (extra = {}) => ({
    publicId: 'line-one',
    sku: 'SYN-SKU',
    item: 'Synthetic item',
    stockUnit: 'ml',
    requestedQuantity: '1.250',
    dispatchedQuantity: null,
    receivedQuantity: null,
    batch: null,
    ...extra,
});
const row = (extra = {}) => ({
    publicId: 'request-one',
    number: 'SYN-REQUEST-ONE',
    status: 'draft',
    supplier: 'Synthetic supplier',
    lockVersion: 2,
    source: 'Synthetic source',
    destination: 'Synthetic destination',
    lines: [line()],
    differences: [],
    ...extra,
});
const settle = async () => {
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
function mount(input = {}) {
    browserDocument.body = node('body');
    browserDocument.activeElement = browserDocument.body;
    const mounts = {};
    const Draft = {
        props: ['publicId'],
        setup(props) {
            mounts[props.publicId] = (mounts[props.publicId] ?? 0) + 1;
            const value = ref('');

            return () =>
                h('input', {
                    'data-draft': props.publicId,
                    value: value.value,
                    onInput: (event) => (value.value = event.target.value),
                });
        },
    };
    const props = reactive({
        rows: [row()],
        branchId: 1,
        busy: false,
        statusLabel: (row) =>
            row.closeKind === 'short' ? 'Closed short' : row.status,
        ...input,
    });
    const root = node('root');
    const app = renderer.createApp({
        render: () =>
            h(component, props, {
                default: ({ row: request }) =>
                    request.status === 'draft'
                        ? h(Draft, {
                              key: request.publicId,
                              publicId: request.publicId,
                          })
                        : h('p', 'Existing governed action slot'),
            }),
    });
    app.mount(root);

    return {
        props,
        root,
        app,
        mounts,
        triggers: () =>
            all(root).filter(
                (item) => item.type === 'button' && item.dataset.poTrigger,
            ),
        drafts: () =>
            all(root).filter(
                (item) => item.type === 'input' && item.props['data-draft'],
            ),
    };
}

const visible = (root) => all(root).filter((n) => n.getClientRects().length);
const filter = (view) =>
    all(view.root).find(
        (n) =>
            n.type === 'input' &&
            n.props['aria-label'] === 'Search loaded orders',
    );
const status = (view) => all(view.root).find((n) => n.type === 'select');
test('real order summary shows supplied identity and line count within explicit newest50 scope', () => {
    const v = mount({
        rows: [
            row({
                status: 'closed',
                closeKind: 'short',
                lines: [line(), line({ publicId: 'two' })],
            }),
        ],
    });

    for (const expected of [
        'Synthetic supplier',
        'Synthetic destination',
        'Closed short',
        'newest 50 orders',
        'matching loaded orders',
    ]) {
        assert.ok(text(v.root).includes(expected), expected);
    }

    assert.equal(v.triggers()[0].props['aria-expanded'], false);
    assert.equal(
        v.triggers()[0].props['aria-label'],
        'Open purchase order SYN-REQUEST-ONE',
    );
    assert.equal(
        all(v.root).some((n) => n.text === '2'),
        true,
    );
    assert.equal(text(v.root).includes('Sent to supplier'), false);
    v.app.unmount();
});
test('local status and search filter loaded rows without remounting drafts or making a global count', async () => {
    const v = mount({
        rows: [
            row(),
            row({
                publicId: 'two',
                number: 'SECOND',
                supplier: 'Other supplier',
                status: 'approved',
            }),
        ],
    });
    v.triggers()[0].props.onClick();
    await settle();
    v.drafts()[0].props.onInput({ target: { value: 'KEEP' } });
    filter(v).props.onInput({ target: { value: 'other' } });
    await settle();
    assert.equal(
        visible(v.root).some((n) => n.props['data-po-row'] === 'request-one'),
        false,
    );
    filter(v).props.onInput({ target: { value: '' } });
    status(v).props['onUpdate:modelValue']('cancelled');
    await settle();
    assert.ok(text(v.root).includes('No loaded orders match'));
    status(v).props['onUpdate:modelValue']('all');
    await settle();
    assert.equal(v.drafts()[0].props.value, 'KEEP');
    assert.equal(v.mounts['request-one'], 1);
    v.props.rows = [];
    await settle();
    assert.ok(text(v.root).includes('No purchase orders are available'));
    v.app.unmount();
});
test('pending command pins selected row and disables disclosure/filter while all detail drafts remain mounted', async () => {
    const v = mount({
        rows: [row(), row({ publicId: 'two', number: 'SECOND' })],
    });
    v.triggers()[0].props.onClick();
    await settle();
    v.props.busy = true;
    await settle();
    v.triggers()[1].props.onClick();
    filter(v).props.onInput({ target: { value: 'SECOND' } });
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], true);
    assert.equal(filter(v).props.disabled, true);
    assert.equal(status(v).props.disabled, true);
    assert.equal(
        visible(v.root).some((n) => n.props['data-po-row'] === 'request-one'),
        true,
    );
    assert.equal(v.mounts.two, 1);
    v.app.unmount();
});
test('row replacement restores disabled-blur focus to surviving trigger, then named list if removed', async () => {
    const v = mount();
    v.triggers()[0].props.onClick();
    await settle();
    const trigger = v.triggers()[0];
    trigger.focus();
    const section = all(v.root).find(
        (n) => n.props['aria-label'] === 'Purchase orders worklist',
    );
    section.props.onFocusin({ target: trigger });
    trigger.disabled = true;
    section.props.onFocusout({ target: trigger, relatedTarget: null });
    browserDocument.activeElement = browserDocument.body;
    v.props.rows = [row({ lockVersion: 3 })];
    await settle();
    assert.equal(browserDocument.activeElement, v.triggers()[0]);
    const draft = v.drafts()[0];
    draft.focus();
    v.props.rows = [];
    await settle();
    assert.equal(browserDocument.activeElement, section);
    v.app.unmount();
});
test('branch refresh clears selection and intentional outside focus is never stolen', async () => {
    const v = mount();
    v.triggers()[0].props.onClick();
    await settle();
    const outside = node('button');
    browserDocument.activeElement = outside;
    v.props.branchId = 2;
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], false);
    assert.equal(browserDocument.activeElement, outside);
    v.app.unmount();
});

test('reactive branch replacement without a document clears local selection safely', async () => {
    const exports = {};
    runInNewContext(
        ts.transpileModule(
            compileScript(
                parse(
                    read(
                        'resources/js/components/inventory/StockPurchaseOrderList.vue',
                    ),
                ).descriptor,
                { id: 'po-no-document' },
            ).content,
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        {
            exports,
            require: (name) =>
                name === 'vue'
                    ? { ...require('vue'), useId: () => 'no-document' }
                    : name.startsWith('@/') || name === '@lucide/vue'
                      ? new Proxy({}, { get: () => ({}) })
                      : require(name),
        },
    );
    const props = reactive({
        rows: [row()],
        branchId: 1,
        busy: false,
        statusLabel: (r) => r.status,
    });
    const scope = require('vue').effectScope();
    const state = scope.run(() =>
        exports.default.setup(props, { expose() {} }),
    );
    state.toggle('request-one');
    props.branchId = 2;
    await settle();
    assert.equal(state.selected.value, null);
    scope.stop();
});
