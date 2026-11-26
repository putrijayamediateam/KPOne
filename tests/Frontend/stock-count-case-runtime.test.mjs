import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, reactive } from 'vue';

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
        parse(read('resources/js/components/inventory/StockCountCaseList.vue'))
            .descriptor,
        { id: 'count-cases-test', inlineTemplate: true },
    ).content,
    (name) =>
        name === '@lucide/vue'
            ? { ChevronRight: { render: () => h('i') } }
            : require(name),
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
                countCase: this.props['data-count-case'],
                caseTrigger: this.props['data-case-trigger'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.countCase
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
                (item) => item.type === 'button' && item.dataset.caseTrigger,
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
    id: 1,
    public_id: 'case-one',
    sku_code: 'SYN-COUNT',
    batch_number: 'B-ONE',
    location: 'Store',
    kind: 'count',
    state: 'awaiting_count',
    blind: true,
    ...extra,
});
const settle = async () => {
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
function mount(extra = {}) {
    browserDocument.activeElement = null;
    const props = reactive({
        rows: [
            row(),
            row({ id: 2, public_id: 'case-two', batch_number: 'B-TWO' }),
        ],
        enabled: true,
        branchId: 1,
        busy: false,
        states: {
            awaiting_count: 'Counter must enter the first count',
            awaiting_approval: 'Independent approval required',
        },
        caseLabel: () => 'Stock count',
        ...extra,
    });
    const root = node('root');
    const mounts = {};
    const Detail = {
        props: ['identity', 'blind'],
        setup(p) {
            mounts[p.identity] = (mounts[p.identity] ?? 0) + 1;

            return () =>
                h('article', [
                    h('input', { 'data-draft': p.identity }),
                    h('p', p.blind ? 'Blind evidence' : 'Approved projection'),
                ]);
        },
    };
    renderer
        .createApp({
            render: () =>
                h(component, props, {
                    default: ({ row: current }) =>
                        h(Detail, {
                            key: current.public_id,
                            identity: current.public_id,
                            blind: current.blind,
                        }),
                }),
        })
        .mount(root);

    return {
        props,
        root,
        mounts,
        buttons: () => all(root).filter((n) => n.dataset.caseTrigger),
    };
}
test('count case summaries preserve exact state and blind label without exposing surplus quantity', () => {
    const { root } = mount({
        rows: [row({ quantity: '98765.432', expected_quantity: '98765.432' })],
    });
    assert.match(text(root), /Counter must enter the first count/);
    assert.match(text(root), /system quantity hidden/);
    assert.doesNotMatch(text(root), /98765/);
});
test('selected details remain mounted while switching and busy commands prevent collapse', async () => {
    const m = mount();
    const first = m.buttons()[0];
    first.props.onClick();
    await settle();
    assert.equal(first.props['aria-expanded'], true);
    m.buttons()[1].props.onClick();
    await settle();
    assert.deepEqual(m.mounts, { 'case-one': 1, 'case-two': 1 });
    m.props.busy = true;
    await settle();
    m.buttons()[0].props.onClick();
    await settle();
    assert.equal(m.buttons()[1].props['aria-expanded'], true);
    assert.equal(m.buttons()[0].props.disabled, true);
});
test('same case identity survives projection replacement but branch change clears selection', async () => {
    const m = mount();
    m.buttons()[0].props.onClick();
    await settle();
    m.props.rows = [row({ state: 'awaiting_approval', blind: false })];
    await settle();
    assert.equal(m.buttons()[0].props['aria-expanded'], true);
    assert.match(text(m.root), /Independent approval required/);
    m.props.branchId = 2;
    await settle();
    assert.equal(m.buttons()[0].props['aria-expanded'], false);
});
test('disabled writeoff path renders only unchanged detail slots without case disclosures', () => {
    const m = mount({ enabled: false });
    assert.equal(m.buttons().length, 0);
    assert.equal(all(m.root).filter((n) => n.type === 'article').length, 2);
    assert.equal(all(m.root).filter((n) => n.type === 'section').length, 0);
});
test('removed focused case falls back to persistent list without stealing outside focus', async () => {
    const m = mount();
    m.buttons()[0].focus();
    m.props.rows = [];
    await settle();
    assert.equal(
        browserDocument.activeElement,
        all(m.root).find((n) => n.type === 'section'),
    );
    const n = mount();
    n.buttons()[0].focus();
    n.props.rows = [];
    await nextTick();
    const outside = node('button');
    outside.focus();
    await settle();
    assert.equal(browserDocument.activeElement, outside);
});
test('disabled trigger blur retains the case focus identity after server replacement', async () => {
    const m = mount();
    const section = all(m.root).find((n) => n.type === 'section');
    const trigger = m.buttons()[0];
    trigger.focus();
    section.props.onFocusin({ target: trigger });
    trigger.disabled = true;
    section.props.onFocusout({ target: trigger, relatedTarget: null });
    browserDocument.activeElement = browserDocument.body;
    m.props.rows = [row()];
    await settle();
    assert.equal(browserDocument.activeElement, m.buttons()[0]);
});

test('reactive server-side case updates do not reference an absent document', async () => {
    const output = { exports: {} };
    const code = compileScript(
        parse(read('resources/js/components/inventory/StockCountCaseList.vue'))
            .descriptor,
        { id: 'count-case-no-document', inlineTemplate: true },
    ).content;
    runInNewContext(
        ts.transpileModule(code, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            exports: output.exports,
            require: (name) =>
                name === '@lucide/vue'
                    ? { ChevronRight: { render: () => h('i') } }
                    : require(name),
        },
    );
    const props = reactive({
        rows: [row()],
        enabled: true,
        branchId: 1,
        busy: false,
        states: {},
        caseLabel: () => 'Stock count',
    });
    const root = node('root');
    renderer
        .createApp({ render: () => h(output.exports.default, props) })
        .mount(root);
    props.rows = [];
    await settle();
    assert.equal(all(root).filter((n) => n.dataset.caseTrigger).length, 0);
});
