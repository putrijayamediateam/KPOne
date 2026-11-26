import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, reactive } from 'vue';

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
const words = evaluate(read('resources/js/lib/inventory-picker.ts'));
const component = evaluate(
    compileScript(
        parse(read('resources/js/components/inventory/StockBatchList.vue'))
            .descriptor,
        { id: 'stock-test', inlineTemplate: true },
    ).content,
    (name) => {
        if (name === '@lucide/vue') {
            return { ChevronRight: { render: () => h('i') } };
        }

        if (name === '@/lib/inventory-picker') {
            return words;
        }

        if (name === '@/components/ui/status') {
            return {
                StatusBadge: {
                    props: ['label'],
                    setup: (props) => () => h('span', props.label),
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
        get dataset() {
            return { batchKey: this.props['data-batch-key'] };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.type === 'button' && this.dataset.batchKey
                ? this
                : (this.parent?.closest() ?? null);
        },
        querySelectorAll() {
            return all(this).filter(
                (item) => item.type === 'button' && item.dataset.batchKey,
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
    skuCode: 'SYN-BATCH-SKU',
    itemName: 'Synthetic item',
    medicineName: null,
    batchNumber: 'SYN-BATCH',
    location: 'Synthetic store',
    quantity: '1.25',
    stockUnit: 'ml',
    expiryDate: '1 Jan 2020',
    expiryStatus: 'Expired',
    batchStatus: 'Held',
    holdReason: 'suspected_damage',
    receivedDate: null,
    ...extra,
});
function mount(input = {}) {
    const props = reactive({ rows: [row()], loading: false, ...input });
    const root = node('root');
    const app = renderer.createApp({ render: () => h(component, props) });
    app.mount(root);

    return {
        props,
        root,
        app,
        expanders: () =>
            all(root).filter(
                (item) =>
                    item.type === 'button' && 'aria-expanded' in item.props,
            ),
    };
}
test('batch rows preserve exact quantity/unit/location and separate expired and held statuses', () => {
    const view = mount();

    for (const value of [
        '1.25 ml',
        'Synthetic store',
        'Expired',
        'Held: Suspected damage',
        'SYN-BATCH',
        'Not mapped to Medicine',
        'Received',
        '—',
    ]) {
        assert.ok(text(view.root).includes(value), value);
    }

    assert.match(text(view.root), /one batch at one location/);
    assert.doesNotMatch(
        text(view.root),
        /Nearest expiry|Report difference|Write off|Count open/,
    );
    assert.equal(
        all(view.root).filter((item) => item.type === 'button').length,
        1,
    );
    view.app.unmount();
});
test('zero quantity and held-without-reason remain explicit, with no inferred expiry classification', () => {
    const view = mount({
        rows: [
            row({
                quantity: '0',
                holdReason: null,
                expiryStatus: 'Valid',
                receivedDate: '4 May 2025',
                medicineName: 'Mapped medicine',
            }),
        ],
    });

    for (const value of [
        '0 ml',
        'Held',
        'Valid',
        '1 Jan 2020',
        '4 May 2025',
        'Mapped medicine',
    ]) {
        assert.ok(text(view.root).includes(value), value);
    }

    assert.doesNotMatch(text(view.root), /Held: null|Held: undefined|Expired/);
    view.app.unmount();
});
test('batch disclosures have distinct controls, expand independently and reset on replaced rows', async () => {
    const view = mount({ rows: [row(), row({ location: 'Other location' })] });
    const buttons = view.expanders();
    assert.notEqual(
        buttons[0].props['aria-controls'],
        buttons[1].props['aria-controls'],
    );
    assert.equal(buttons[0].props['aria-expanded'], false);
    buttons[0].props.onClick();
    await nextTick();
    assert.equal(view.expanders()[0].props['aria-expanded'], true);
    assert.equal(view.expanders()[1].props['aria-expanded'], false);
    buttons[1].props.onClick();
    await nextTick();
    assert.equal(view.expanders()[0].props['aria-expanded'], true);
    assert.equal(view.expanders()[1].props['aria-expanded'], true);
    view.props.rows = [row({ batchNumber: 'REPLACEMENT' })];
    await nextTick();
    assert.equal(view.expanders()[0].props['aria-expanded'], false);
    assert.match(text(view.root), /REPLACEMENT/);
    view.app.unmount();
});
test('loading removes stale batch controls and data until the new server projection arrives', async () => {
    const view = mount();
    view.props.loading = true;
    await nextTick();
    assert.equal(view.expanders().length, 0);
    assert.match(text(view.root), /Loading records/);
    assert.doesNotMatch(text(view.root), /SYN-BATCH/);
    view.props.rows = [row({ batchNumber: 'AFTER-LOAD' })];
    view.props.loading = false;
    await nextTick();
    assert.match(text(view.root), /AFTER-LOAD/);
    assert.equal(view.expanders()[0].props['aria-expanded'], false);
    view.app.unmount();
});

test('focused batch keeps its trigger after row reordering and falls back to the labeled list when removed', async () => {
    const view = mount({ rows: [row(), row({ batchNumber: 'SECOND' })] });
    await nextTick();
    view.expanders()[0].focus();
    view.props.rows = [row({ batchNumber: 'SECOND' }), row()];
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
    assert.ok(browserDocument.activeElement === view.expanders()[1]);
    view.props.rows = [row({ batchNumber: 'SECOND' })];
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(browserDocument.activeElement.type, 'section');
    assert.equal(
        browserDocument.activeElement.props['aria-label'],
        'Batch and expiry balances by location',
    );
    view.app.unmount();
});

test('loading preserves a focus fallback without stealing focus from filters outside the list', async () => {
    const view = mount();
    await nextTick();
    view.expanders()[0].focus();
    view.props.loading = true;
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(browserDocument.activeElement.type, 'section');
    const externalFilter = node('input');
    externalFilter.focus();
    view.props.rows = [row({ batchNumber: 'NEW' })];
    view.props.loading = false;
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(browserDocument.activeElement, externalFilter);
    view.app.unmount();
});

test('a focus move outside the list during the update is not overwritten by the pending restoration', async () => {
    const view = mount();
    await nextTick();
    view.expanders()[0].focus();
    view.props.rows = [row({ batchNumber: 'REPLACED' })];
    await nextTick();
    const externalFilter = node('input');
    externalFilter.focus();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(browserDocument.activeElement, externalFilter);
    view.app.unmount();
});
