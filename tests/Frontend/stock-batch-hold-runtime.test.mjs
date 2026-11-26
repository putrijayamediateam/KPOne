import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, reactive, ref } from 'vue';

const require = createRequire(import.meta.url);
const browserDocument = { activeElement: null, body: {} };
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
        parse(read('resources/js/components/inventory/StockBatchHoldList.vue'))
            .descriptor,
        { id: 'holds-test', inlineTemplate: true },
    ).content,
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
            return {
                holdBatch: this.props['data-hold-batch'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest(selector) {
            if (selector === '[role="dialog"]') {
                return this.props.role === 'dialog'
                    ? this
                    : (this.parent?.closest(selector) ?? null);
            }

            return this.dataset.holdBatch
                ? this
                : (this.parent?.closest(selector) ?? null);
        },
        get disabled() {
            return this.props.disabled === true;
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
        querySelectorAll(selector) {
            return all(this).filter((item) =>
                selector === 'button'
                    ? item.type === 'button'
                    : item.dataset.holdBatch,
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
    public_id: 'batch-one',
    sku_code: 'SYN-HOLD',
    batch_number: 'B-ONE',
    expiry_date: '2027-12-31',
    status: 'active',
    reason: null,
    canPlace: true,
    canRelease: false,
    ...extra,
});
const settle = async () => {
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
function mount(extra = {}) {
    browserDocument.activeElement = browserDocument.body;
    const props = reactive({
        rows: [row()],
        branchId: 1,
        activeBatchId: 'batch-one',
        busy: false,
        ...extra,
    });
    const root = node('root');
    const instance = ref(null);
    renderer
        .createApp({
            render: () =>
                h(
                    component,
                    { ...props, ref: instance },
                    {
                        expiry: ({ row: r }) => h('span', r.expiry_date),
                        status: ({ row: r }) =>
                            h(
                                'p',
                                `Batch status ${r.status}; Branch hold ${r.reason ?? 'No active branch hold'}`,
                            ),
                        actions: ({ row: r }) =>
                            r.canPlace
                                ? h('button', 'Place hold')
                                : r.canRelease
                                  ? h('button', 'Release hold')
                                  : h('p', 'Independent checker required'),
                    },
                ),
        })
        .mount(root);

    return {
        props,
        root,
        instance,
        restore: async () => {
            let prevented = false;
            instance.value.restoreFocus({
                preventDefault() {
                    prevented = true;
                },
            });
            await settle();

            return prevented;
        },
    };
}
test('global batch status and branch reason remain distinct and no quantity is invented', () => {
    const m = mount({
        rows: [
            row({ status: 'held', quantity: '98765.432' }),
            row({
                public_id: 'batch-two',
                status: 'active',
                reason: 'Recall',
                canPlace: false,
                canRelease: false,
            }),
        ],
    });
    assert.match(
        text(m.root),
        /Batch status held; Branch hold No active branch hold/,
    );
    assert.match(text(m.root), /Batch status active; Branch hold Recall/);
    assert.doesNotMatch(text(m.root), /98765/);
    assert.equal(all(m.root).filter((n) => n.type === 'button').length, 1);
});
test('successful Place clears selected but restores surviving batch row when no release is authorized', async () => {
    const m = mount();
    m.props.rows = [row({ reason: 'Recall', canPlace: false })];
    m.props.activeBatchId = null;
    await settle();
    assert.equal(await m.restore(), true);
    assert.equal(
        browserDocument.activeElement,
        all(m.root).find((n) => n.dataset.holdBatch === 'batch-one'),
    );
});
test('independent release restores the now-available Place action', async () => {
    const m = mount({
        rows: [row({ reason: 'Recall', canPlace: false, canRelease: true })],
    });
    m.props.rows = [row()];
    m.props.activeBatchId = null;
    await settle();
    await m.restore();
    assert.equal(
        browserDocument.activeElement,
        all(m.root).find((n) => n.type === 'button'),
    );
});
test('removed batch falls back to named list and branch change cancels old return identity', async () => {
    const m = mount();
    m.props.rows = [];
    m.props.activeBatchId = null;
    await settle();
    await m.restore();
    assert.equal(
        browserDocument.activeElement,
        all(m.root).find((n) => n.type === 'section'),
    );
    const n = mount();
    n.props.branchId = 2;
    n.props.activeBatchId = null;
    await settle();
    assert.equal(await n.restore(), false);
    assert.equal(browserDocument.activeElement, browserDocument.body);
});
test('outside focus before or during close restoration is not stolen', async () => {
    const m = mount();
    const outside = node('button');
    outside.focus();
    assert.equal(await m.restore(), true);
    assert.equal(browserDocument.activeElement, outside);
    browserDocument.activeElement = browserDocument.body;
    m.instance.value.restoreFocus({ preventDefault() {} });
    outside.focus();
    await settle();
    assert.equal(browserDocument.activeElement, outside);
});
test('empty search retains stable named fallback without invented actions', () => {
    const m = mount({ rows: [] });
    assert.match(text(m.root), /No batches match this search/);
    assert.equal(all(m.root).filter((n) => n.type === 'button').length, 0);
    assert.equal(
        all(m.root).find((n) => n.type === 'section').props['aria-label'],
        'Batch safety hold worklist',
    );
});
