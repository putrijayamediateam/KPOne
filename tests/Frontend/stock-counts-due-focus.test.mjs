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
        parse(read('resources/js/components/inventory/StockCountsDueList.vue'))
            .descriptor,
        { id: 'counts-focus-test', inlineTemplate: true },
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
                countRow: this.props['data-count-row'],
            };
        },
        focus() {
            browserDocument.activeElement = this;
        },
        contains(target) {
            return all(this).includes(target);
        },
        closest() {
            return this.dataset.countRow
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
            return all(this).filter((item) => item.dataset.countRow);
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
const row = (extra = {}) => ({
    skuPublicId: 'sku',
    skuCode: 'SYN',
    item: 'Synthetic',
    locationPublicId: 'store',
    location: 'Store',
    class: 'A',
    lastCounted: null,
    dueOn: null,
    overdueDays: null,
    open: false,
    ...extra,
});
const settle = async () => {
    await nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
const mount = () => {
    browserDocument.activeElement = null;
    const props = reactive({ rows: [row()] });
    const root = node('root');
    renderer
        .createApp({
            render: () =>
                h(component, props, {
                    action: ({ row: current }) =>
                        current.open
                            ? h('p', 'Counts open below')
                            : h('button', 'Start count'),
                }),
        })
        .mount(root);

    return { props, root };
};
test('completed Start action returns focus to the surviving item row', async () => {
    const { props, root } = mount();
    const button = all(root).find((n) => n.type === 'button');
    button.focus();
    props.rows = [row({ open: true })];
    await settle();
    assert.equal(
        browserDocument.activeElement,
        all(root).find((n) => n.dataset.countRow === 'sku:store'),
    );
});
test('removed due row returns focus to the persistent named list', async () => {
    const { props, root } = mount();
    all(root)
        .find((n) => n.type === 'button')
        .focus();
    props.rows = [];
    await settle();
    assert.equal(
        browserDocument.activeElement,
        all(root).find((n) => n.type === 'ul'),
    );
});
test('pending restoration never steals focus moved outside the worklist', async () => {
    const { props, root } = mount();
    all(root)
        .find((n) => n.type === 'button')
        .focus();
    props.rows = [row({ open: true })];
    await nextTick();
    const outside = node('button');
    outside.focus();
    await settle();
    assert.equal(browserDocument.activeElement, outside);
});

test('disabled Start blur before server refresh still restores its request row', async () => {
    const { props, root } = mount();
    const list = all(root).find((n) => n.type === 'ul');
    const button = all(root).find((n) => n.type === 'button');
    button.focus();
    list.props.onFocusin({ target: button });
    button.disabled = true;
    list.props.onFocusout({ target: button, relatedTarget: null });
    browserDocument.activeElement = browserDocument.body;
    props.rows = [row({ open: true })];
    await settle();
    assert.equal(
        browserDocument.activeElement,
        all(root).find((n) => n.dataset.countRow === 'sku:store'),
    );
});
test('ordinary blur to the page does not restore focus after a response', async () => {
    const { props, root } = mount();
    const list = all(root).find((n) => n.type === 'ul');
    const button = all(root).find((n) => n.type === 'button');
    button.focus();
    list.props.onFocusin({ target: button });
    list.props.onFocusout({ target: button, relatedTarget: null });
    browserDocument.activeElement = browserDocument.body;
    props.rows = [row({ open: true })];
    await settle();
    assert.equal(browserDocument.activeElement, browserDocument.body);
});
