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
const words = evaluate(read('resources/js/lib/supplier-terms.ts'));
const component = evaluate(
    compileScript(
        parse(read('resources/js/components/inventory/StockTransferList.vue'))
            .descriptor,
        { id: 'stock-test', inlineTemplate: true },
    ).content,
    (name) => {
        if (name === '@lucide/vue') {
            return { ChevronRight: { render: () => h('i') } };
        }

        if (name === '@/lib/supplier-terms') {
            return words;
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
        get dataset() {
            return {
                requestRow: this.props['data-request-row'],
                requestTrigger: this.props['data-request-trigger'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.requestRow
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
                (item) => item.type === 'button' && item.dataset.requestTrigger,
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
    status: 'requested',
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
    browserDocument.activeElement = null;
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
        ...input,
    });
    const root = node('root');
    const app = renderer.createApp({
        render: () =>
            h(component, props, {
                details: ({ row: request }) =>
                    request.status === 'requested'
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
                (item) => item.type === 'button' && item.dataset.requestTrigger,
            ),
        drafts: () =>
            all(root).filter(
                (item) => item.type === 'input' && item.props['data-draft'],
            ),
    };
}
test('requests display exact per-line units and independent received/open-difference state without mixed totals', () => {
    const view = mount({
        rows: [
            row({
                status: 'received',
                lines: [
                    line({ dispatchedQuantity: '0.000' }),
                    line({
                        publicId: 'line-two',
                        stockUnit: 'tablet',
                        requestedQuantity: '3.000',
                    }),
                ],
                differences: [{ status: 'open' }, { status: 'resolved' }],
            }),
        ],
    });

    for (const value of [
        'Request SYN-REQUEST-ONE',
        '2 lines',
        'Synthetic source',
        'Synthetic destination',
        '1.25 ml',
        '3 tablet',
        '0 ml',
        'received',
        '1 difference open',
        '—',
    ]) {
        assert.ok(text(view.root).includes(value), value);
    }

    assert.doesNotMatch(
        text(view.root),
        /4.25|Total quantity|Automatically resolved/,
    );
    assert.equal(view.triggers().length, 1);
    view.app.unmount();
});
test('selection and collapse keep each scoped action draft mounted and retain typed values', async () => {
    const view = mount({
        rows: [row(), row({ publicId: 'request-two', number: 'SYN-TWO' })],
    });
    assert.equal(view.drafts().length, 2);
    const original = view.drafts()[0];
    view.triggers()[0].props.onClick();
    original.props.onInput({ target: { value: '3.25' } });
    await settle();
    view.triggers()[1].props.onClick();
    await settle();
    assert.equal(view.triggers()[0].props['aria-expanded'], false);
    assert.equal(view.triggers()[1].props['aria-expanded'], true);
    view.triggers()[1].props.onClick();
    await settle();
    view.triggers()[0].props.onClick();
    await settle();
    assert.equal(view.drafts()[0], original);
    assert.equal(view.drafts()[0].props.value, '3.25');
    assert.deepEqual(view.mounts, { 'request-one': 1, 'request-two': 1 });
    view.app.unmount();
});
test('busy commands retain their selected request and prevent hiding the pending action context', async () => {
    const view = mount({ rows: [row(), row({ publicId: 'request-two' })] });
    view.triggers()[0].props.onClick();
    await settle();
    view.props.busy = true;
    await settle();

    for (const trigger of view.triggers()) {
        assert.equal(trigger.props.disabled, true);
        trigger.props.onClick();
    }

    await settle();
    assert.equal(view.triggers()[0].props['aria-expanded'], true);
    assert.equal(view.triggers()[1].props['aria-expanded'], false);
    view.props.busy = false;
    await settle();
    view.triggers()[1].props.onClick();
    await settle();
    assert.equal(view.triggers()[1].props['aria-expanded'], true);
    view.app.unmount();
});
test('stable request identity survives refresh/reordering but branch changes and removal clear selection', async () => {
    const first = row();
    const second = row({ publicId: 'request-two' });
    const view = mount({ rows: [first, second] });
    view.triggers()[0].props.onClick();
    await settle();
    view.props.rows = [second, { ...first, lockVersion: 3 }];
    await settle();
    assert.equal(view.triggers()[1].props['aria-expanded'], true);
    view.props.branchId = 2;
    await settle();
    assert.ok(
        view
            .triggers()
            .every((trigger) => trigger.props['aria-expanded'] === false),
    );
    view.triggers()[1].props.onClick();
    await settle();
    view.props.rows = [second];
    await settle();
    assert.equal(view.triggers()[0].props['aria-expanded'], false);
    view.app.unmount();
});
test('a removed focused action restores the surviving request trigger and a removed request falls back to the list', async () => {
    const view = mount();
    view.triggers()[0].props.onClick();
    await settle();
    view.drafts()[0].focus();
    view.props.rows = [row({ status: 'approved' })];
    await settle();
    assert.equal(browserDocument.activeElement, view.triggers()[0]);
    view.props.rows = [];
    await settle();
    assert.equal(browserDocument.activeElement.type, 'section');
    assert.equal(
        browserDocument.activeElement.props['aria-label'],
        'Recent stock requests and transfers',
    );
    assert.match(text(view.root), /No recent stock requests/);
    view.app.unmount();
});
test('pending request focus recovery does not steal a newly focused external control', async () => {
    const view = mount();
    view.triggers()[0].props.onClick();
    await settle();
    view.drafts()[0].focus();
    view.props.rows = [];
    await nextTick();
    const outside = node('input');
    outside.focus();
    await settle();
    assert.equal(browserDocument.activeElement, outside);
    view.app.unmount();
});
