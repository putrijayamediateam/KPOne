import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
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
        { exports: output.exports, require: resolve },
    );

    return output.exports;
};
const words = evaluate(read('resources/js/lib/inventory-picker.ts'));
const component = evaluate(
    compileScript(
        parse(read('resources/js/components/inventory/StockOnHandList.vue'))
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

        if (name.endsWith('DifferenceRowMenu.vue')) {
            return {
                default: {
                    props: ['difference', 'label'],
                    emits: ['report'],
                    setup:
                        (props, { emit }) =>
                        () =>
                            h(
                                'button',
                                {
                                    'data-menu': true,
                                    onClick: () =>
                                        emit('report', 'original-trigger'),
                                },
                                props.label,
                            ),
                },
            };
        }

        return require(name);
    },
).default;
const node = (type, text = '') => ({
    type,
    text,
    props: {},
    children: [],
    parent: null,
    style: {},
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
    skuCode: 'SYN-ONE',
    itemName: 'Synthetic item',
    medicineName: 'Synthetic medicine',
    batchNumber: 'SYN-BATCH',
    expiryDate: '2025-01-01',
    quantity: '1.25',
    stockUnit: 'ml',
    location: 'Demo store',
    locationType: 'Store',
    branch: 'Synthetic branch',
    available: false,
    availabilityStatus: 'Expired',
    ...extra,
});
function mount(input = {}) {
    const props = reactive({
        rows: [row()],
        canReport: false,
        loading: false,
        ...input,
    });
    const root = node('root');
    const reports = [];
    const app = renderer.createApp({
        render: () =>
            h(component, {
                ...props,
                onReport: (...event) => reports.push(event),
            }),
    });
    app.mount(root);

    return {
        props,
        root,
        reports,
        app,
        expanders: () =>
            all(root).filter(
                (item) =>
                    item.type === 'button' && 'aria-expanded' in item.props,
            ),
    };
}

test('real row template preserves fractional quantity, unit, expired status and batch/location semantics', () => {
    const view = mount();
    assert.match(text(view.root), /1.25 ml/);
    assert.match(text(view.root), /Expired/);
    assert.match(text(view.root), /SYN-BATCH/);
    assert.match(text(view.root), /one batch at one location/);
    assert.doesNotMatch(text(view.root), /Nearest expiry/);
    assert.equal(
        all(view.root).filter((item) => item.props['data-menu']).length,
        0,
    );
    view.app.unmount();
});

test('row expansion controls its own details, supports multiple open balances and resets on new page projection', async () => {
    const view = mount({ rows: [row(), row({ batchNumber: 'SECOND' })] });
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
    view.expanders()[1].props.onClick();
    await nextTick();
    assert.equal(view.expanders()[0].props['aria-expanded'], true);
    view.props.rows = [row({ batchNumber: 'ANOTHER-PAGE' })];
    await nextTick();
    assert.equal(view.expanders()[0].props['aria-expanded'], false);
    view.app.unmount();
});

test('held reason and zero quantity remain explicit without changing their availability', () => {
    const view = mount({
        rows: [
            row({
                held: true,
                holdReason: 'suspected_damage',
                availabilityStatus: 'Held',
                quantity: '0',
            }),
        ],
    });
    assert.match(text(view.root), /0 ml/);
    assert.match(text(view.root), /[Hh]eld/);
    assert.match(text(view.root), /[Ss]uspected damage/);
    view.app.unmount();
});

test('report action requires both authority and row capability, preserves count label and forwards exact row/trigger', async () => {
    const difference = { openCount: true, balanceVersion: 7 };
    const view = mount({
        rows: [row({ difference }), row({ batchNumber: 'NO-CAPABILITY' })],
    });
    assert.equal(
        all(view.root).filter((item) => item.props['data-menu']).length,
        0,
    );
    view.props.canReport = true;
    await nextTick();
    const menus = all(view.root).filter((item) => item.props['data-menu']);
    assert.equal(menus.length, 1);
    assert.match(text(view.root), /Count open/);
    menus[0].props.onClick();
    assert.equal(view.reports.length, 1);
    assert.equal(view.reports[0][0], view.props.rows[0]);
    assert.equal(view.reports[0][1], 'original-trigger');
    view.props.canReport = false;
    await nextTick();
    assert.equal(
        all(view.root).filter((item) => item.props['data-menu']).length,
        0,
    );
    view.app.unmount();
});

test('loading hides stale balance controls until the new directory projection arrives', async () => {
    const view = mount({
        canReport: true,
        rows: [row({ difference: { openCount: false } })],
    });
    assert.equal(view.expanders().length, 1);
    view.props.loading = true;
    await nextTick();
    assert.equal(view.expanders().length, 0);
    assert.equal(
        all(view.root).filter((item) => item.props['data-menu']).length,
        0,
    );
    assert.match(text(view.root), /Loading records/);
    assert.doesNotMatch(text(view.root), /SYN-BATCH/);
    view.props.rows = [row({ batchNumber: 'NEW-PROJECTION' })];
    view.props.loading = false;
    await nextTick();
    assert.match(text(view.root), /NEW-PROJECTION/);
    assert.equal(view.expanders()[0].props['aria-expanded'], false);
    view.app.unmount();
});
