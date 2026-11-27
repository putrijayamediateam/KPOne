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
                'resources/js/components/inventory/StockSupplierInvoiceList.vue',
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
                invoiceRow: this.props['data-invoice-row'],
                invoiceTrigger: this.props['data-invoice-trigger'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.invoiceRow
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
                (item) => item.type === 'button' && item.dataset.invoiceTrigger,
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
    publicId: 'invoice-one',
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
        showAmounts: false,
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
        triggers: () => all(root).filter((n) => n.dataset.invoiceTrigger),
        draft: () => all(root).find((n) => n.props['data-draft']),
    };
}
test('hidden raw total never reaches summary formatter or rendering', () => {
    let calls = 0;
    const v = mount({
        rows: [row({ totalSen: 987654321 })],
        money: () => {
            calls++;

            return 'PRIVATE-MONEY';
        },
    });
    assert.equal(calls, 0);
    assert.doesNotMatch(text(v.root), /PRIVATE-MONEY|987654321/);
    assert.match(text(v.root), /Not shown/);

    for (const x of [
        'SYN-INV',
        'Synthetic supplier',
        'SYN-PO',
        '27 Nov 2026',
        'waiting',
    ]) {
assert.ok(text(v.root).includes(x));
}

    v.app.unmount();
});
test('authorized zero and absent totals stay distinct', () => {
    const v = mount({
        showAmounts: true,
        rows: [row(), row({ publicId: 'absent', totalSen: undefined })],
    });
    assert.match(text(v.root), /RM 0.00/);
    assert.match(text(v.root), /Unavailable/);
    v.app.unmount();
});
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
    assert.equal(v.mounts['invoice-one'], 1);
    v.app.unmount();
});
test('removed focused invoice falls back to persistent named list without stealing outside focus', async () => {
    const v = mount();
    const trigger = v.triggers()[0];
    trigger.focus();
    v.props.rows = [];
    await settle();
    assert.equal(
        browserDocument.activeElement.props['aria-label'],
        'Supplier invoice matches',
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
test('actual parent decision retains original invoice and version on projection replacement', () => {
    const source = read('resources/js/pages/Inventory/SupplierInvoices.vue');
    const start = source.indexOf('const openDecision =');
    const end = source.indexOf('const decisionError =', start);
    assert.ok(start >= 0 && end > start);
    const decision = reactive({
        invoice: null,
        kind: 'accepted',
        reason: '',
        note: '',
    });
    const calls = [];
    const props = reactive({
        invoices: { expectedBranchId: 1, rows: [row({ lockVersion: 3 })] },
    });
    const output = { exports: {} };
    runInNewContext(
        ts.transpileModule(
            source.slice(start, end) +
                '\nexport {openDecision,submitDecision};',
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        {
            exports: output.exports,
            decision,
            reasonError: ref(''),
            noteError: ref(''),
            busy: ref(false),
            nextTick,
            props,
            router: { post: (...args) => calls.push(args) },
        },
    );
    output.exports.openDecision(props.invoices.rows[0], 'accepted');
    props.invoices.rows = [row({ lockVersion: 9 })];
    decision.reason = 'price_agreed';
    output.exports.submitDecision();
    assert.equal(calls.length, 1);
    assert.equal(
        calls[0][0],
        '/inventory/supplier-invoices/invoice-one/accepted',
    );
    assert.equal(calls[0][1].lock_version, 3);
});

test('disabled-blur restoration remembers the invoice but ordinary outside blur does not', async () => {
    const v = mount();
    const list = all(v.root).find(
        (n) => n.props['aria-label'] === 'Supplier invoice matches',
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
