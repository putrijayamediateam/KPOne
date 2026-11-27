import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import {
    createRenderer,
    h,
    markRaw,
    nextTick,
    reactive,
    ref,
    effectScope,
} from 'vue';

const require = createRequire(import.meta.url);
const browserDocument = { activeElement: null };
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const evaluate = (source, resolve = require, server = false) => {
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
            ...(server ? {} : { document: browserDocument }),
        },
    );

    return output.exports;
};
const component = evaluate(
    compileScript(
        parse(
            read(
                'resources/js/components/inventory/StockSupplierReturnList.vue',
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
                returnRow:
                    this.props['data-return-row'] === undefined
                        ? undefined
                        : String(this.props['data-return-row']),
                returnTrigger:
                    this.props['data-return-trigger'] === undefined
                        ? undefined
                        : String(this.props['data-return-trigger']),
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.returnRow
                ? this
                : (this.parent?.closest() ?? null);
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
                (item) => item.type === 'button' && item.dataset.returnTrigger,
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
const row = (extra = {}) => ({
    publicId: 'return-one',
    id: 11,
    number: 'SYN-INV',
    supplier: 'Synthetic supplier',
    invoiceDate: '27 Nov 2026',
    orderNumber: 'SYN-PO',
    status: 'waiting',
    totalSen: 0,
    ...extra,
});
const settle = async () => {
    await nextTick();
    await new Promise((r) => setImmediate(r));
};
function mount(input = {}) {
    browserDocument.body = node('body');
    browserDocument.activeElement = browserDocument.body;
    const mounts = {};
    const Detail = {
        props: ['publicId'],
        setup(p) {
            mounts[p.publicId] = (mounts[p.publicId] ?? 0) + 1;
            const draft = ref('');

            return () =>
                h('input', {
                    'data-draft': p.publicId,
                    value: draft.value,
                    onInput: (e) => (draft.value = e.target.value),
                });
        },
    };
    const props = reactive({
        rows: [row()],
        branchId: 1,
        contextKey: 'waiting:1',
        busy: false,
        listLabel: 'Supplier return requests',
        emptyText: 'No matching supplier returns.',
        number: (r) => r.number,
        money: (sen) =>
            sen === undefined ? 'Unavailable' : `RM ${(sen / 100).toFixed(2)}`,
        statusLabel: (s) => s,
        statusTone: () => 'neutral',
        ...input,
    });
    const root = node('root');
    const app = renderer.createApp({
        render: () =>
            h(component, props, {
                default: ({ row: r }) =>
                    h(Detail, { publicId: r.publicId, key: r.publicId }),
            }),
    });
    app.mount(root);

    return {
        root,
        props,
        app,
        mounts,
        triggers: () => all(root).filter((n) => n.dataset.returnTrigger),
        draft: () => all(root).find((n) => n.props['data-draft']),
    };
}
test('details stay mounted through disclosure and pending command refuses selection changes', async () => {
    const v = mount();
    v.triggers()[0].props.onClick();
    await settle();
    v.draft().props.onInput({ target: { value: 'RETAIN' } });
    v.props.busy = true;
    await settle();
    assert.equal(v.triggers()[0].props.disabled, true);
    v.triggers()[0].props.onClick();
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], true);
    v.props.busy = false;
    await settle();
    v.triggers()[0].props.onClick();
    await settle();
    assert.equal(v.draft().props.value, 'RETAIN');
    assert.equal(v.mounts['return-one'], 1);
    v.app.unmount();
});
test('removed focused return falls back to persistent named list without stealing outside focus', async () => {
    const v = mount();
    const trigger = v.triggers()[0];
    trigger.focus();
    v.props.rows = [];
    await settle();
    assert.equal(
        browserDocument.activeElement.props['aria-label'],
        'Supplier return requests',
    );
    v.props.rows = [row()];
    await settle();
    const outside = node('outside');
    outside.focus();
    v.props.rows = [];
    await settle();
    assert.equal(browserDocument.activeElement, outside);
    v.app.unmount();
});
test('tab and branch context reset selection while surviving same-page replacement preserves it', async () => {
    const v = mount();
    v.triggers()[0].props.onClick();
    await settle();
    v.props.rows = [row()];
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], true);
    v.props.contextKey = 'accepted:1';
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], false);
    v.triggers()[0].props.onClick();
    await settle();
    v.props.branchId = 2;
    await settle();
    assert.equal(v.triggers()[0].props['aria-expanded'], false);
    v.app.unmount();
});
test('disabled-blur restoration remembers the return but ordinary outside blur does not', async () => {
    const v = mount();
    const list = all(v.root).find(
        (n) => n.props['aria-label'] === 'Supplier return requests',
    );
    const trigger = v.triggers()[0];
    list.props.onFocusin({ target: trigger });
    trigger.disabled = true;
    list.props.onFocusout({ target: trigger, relatedTarget: null });
    browserDocument.activeElement = browserDocument.body;
    v.props.rows = [];
    await settle();
    assert.equal(browserDocument.activeElement, list);
    v.props.rows = [row()];
    await settle();
    const next = v.triggers()[0];
    list.props.onFocusin({ target: next });
    list.props.onFocusout({ target: next, relatedTarget: node('outside') });
    browserDocument.activeElement = browserDocument.body;
    v.props.rows = [];
    await settle();
    assert.equal(browserDocument.activeElement, browserDocument.body);
    v.app.unmount();
});

test('separate mounted lists keep independent selection even when numeric IDs coincide', async () => {
    const group = mount({ listLabel: 'Grouped returns' });
    const single = mount({ listLabel: 'Individual returns' });
    group.triggers()[0].props.onClick();
    await settle();
    assert.equal(group.triggers()[0].props['aria-expanded'], true);
    assert.equal(single.triggers()[0].props['aria-expanded'], false);
    single.triggers()[0].props.onClick();
    await settle();
    assert.equal(group.triggers()[0].props['aria-expanded'], true);
    assert.equal(single.triggers()[0].props['aria-expanded'], true);
    group.app.unmount();
    single.app.unmount();
});

test('actual parent captures group and individual command identity and version before refreshed rows', () => {
    const source = read('resources/js/pages/Inventory/SupplierReturns.vue');
    const start = source.indexOf('function showGroupAction(');
    const end = source.indexOf('function submit()', start);
    assert.ok(start >= 0 && end > start);
    const form = { reset() {}, clearErrors() {} };
    const props = reactive({
        returns: { branchId: 1, groups: { rows: [] }, returns: { rows: [] } },
    });
    const state = {
        form,
        props,
        label: ref(''),
        operation: ref(''),
        endpoint: ref(''),
        isGroup: ref(false),
        activeGroup: ref(null),
        open: ref(false),
        crypto: { randomUUID: () => 'stable-key' },
    };
    const output = { exports: {} };
    runInNewContext(
        ts.transpileModule(
            source.slice(start, end) + '\nexport {showGroupAction,showAction};',
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        { exports: output.exports, ...state },
    );
    const original = {
        id: 19,
        lockVersion: 3,
        groupNumber: 'GROUP-19',
        supplier: 'Synthetic supplier',
        deliveryReference: 'DEL-1',
        lines: [{ quantity: '0.000', base_unit: 'tablet' }],
    };
    props.returns.groups.rows = [original];
    output.exports.showGroupAction(original, 'approved');
    props.returns.groups.rows = [{ ...original, id: 20, lockVersion: 9 }];
    assert.equal(
        state.endpoint.value,
        '/inventory/supplier-return-groups/19/approved',
    );
    assert.equal(form.lock_version, 3);
    assert.equal(state.activeGroup.value.id, 19);
    assert.equal(form.retry_key, 'stable-key');
    output.exports.showAction(
        {
            id: 41,
            lock_version: 7,
            return_number: 'RETURN-41',
            quantity: '0.001',
            base_unit: 'capsule',
        },
        'dispatched',
    );
    props.returns.returns.rows = [{ id: 42, lock_version: 8 }];
    assert.equal(
        state.endpoint.value,
        '/inventory/supplier-returns/41/dispatched',
    );
    assert.equal(form.lock_version, 7);
    assert.equal(state.activeGroup.value, null);
    assert.equal(form.expected_branch_id, 1);
});

test('empty filtered list retains its named focus fallback and supplied truthful message', () => {
    const view = mount({
        rows: [],
        emptyText: 'No matching supplier returns.',
    });
    assert.match(text(view.root), /No matching supplier returns\./);
    assert.ok(
        all(view.root).some(
            (n) =>
                n.props['aria-label'] === 'Supplier return requests' &&
                n.props.tabindex === '-1',
        ),
    );
    assert.equal(view.triggers().length, 0);
    view.app.unmount();
});

test('reactive replacement is safe without a document during server setup', async () => {
    const source = compileScript(
        parse(
            read(
                'resources/js/components/inventory/StockSupplierReturnList.vue',
            ),
        ).descriptor,
        { id: 'server-return', inlineTemplate: true },
    ).content;
    const component = evaluate(
        source,
        (name) =>
            name === '@lucide/vue'
                ? { ChevronRight: { render: () => null } }
                : require(name),
        true,
    ).default;
    const scope = effectScope();
    const props = reactive({
        rows: [{ id: 1 }],
        branchId: 1,
        contextKey: 'all:1',
        busy: false,
        number: () => 'SYN',
        listLabel: 'Returns',
        emptyText: 'None',
    });
    scope.run(() => component.setup(props, { expose() {} }));
    props.rows = [];
    props.branchId = 2;
    await nextTick();
    assert.equal(props.rows.length, 0);
    scope.stop();
});
