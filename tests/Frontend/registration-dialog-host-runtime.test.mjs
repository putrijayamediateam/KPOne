import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/components/registration/RegistrationCreateDialog.vue',
    'utf8',
);
const renderer = createRenderer({
    createElement: (tag) => ({ tag, children: [] }),
    createText: (text) => ({ text }),
    createComment: (text) => ({ text }),
    insert: (node, parent) => {
        node.parent = parent;
        parent.children.push(node);
    },
    remove: () => {},
    setText: () => {},
    setElementText: () => {},
    parentNode: (node) => node.parent,
    nextSibling: () => null,
    patchProp: () => {},
});
const payload = () => ({
    options: {
        idempotencyKey: 'synthetic-key',
        branch: { id: 9, name: 'Synthetic' },
        doctors: [],
        panels: [],
    },
    recentPatients: [],
    requirements: {},
});
function harness() {
    const requests = [];
    const events = [];
    const listeners = new Map();
    const props = reactive({
        open: true,
        branchId: 9,
        canCreate: true,
        opener: null,
        'onUpdate:open': (value) => events.push(['open', value]),
        onRegistered: () => events.push(['registered']),
    });
    let state;
    let guard;
    let removed = false;
    let focused = 0;
    class Element {
        isConnected = true;
        focus() {
            focused++;
        }
    }
    const module = { exports: {} };
    const code = compileScript(parse(source).descriptor, {
        id: 'dialog-host',
    }).content;
    runInNewContext(
        ts.transpileModule(code, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            exports: module.exports,
            require: (name) => {
                if (name === 'vue') {
                    return require(name);
                }

                if (name === '@inertiajs/vue3') {
                    return {
                        router: {
                            on: (_, callback) => {
                                guard = callback;

                                return () => {
                                    removed = true;
                                };
                            },
                        },
                    };
                }

                if (name === '@/lib/json-client') {
                    return {
                        JsonRequestError: class extends Error {},
                        requestJson: (url, options) =>
                            new Promise((resolve, reject) =>
                                requests.push({
                                    url,
                                    options,
                                    resolve,
                                    reject,
                                }),
                            ),
                    };
                }

                return new Proxy({}, { get: () => () => null });
            },
            AbortController,
            HTMLElement: Element,
            document: { querySelector: () => ({ focus: () => focused++ }) },
            window: {
                addEventListener: (name, callback) =>
                    listeners.set(name, callback),
                removeEventListener: (name) => listeners.delete(name),
                confirm: () => false,
            },
        },
    );
    const component = module.exports.default;
    const setup = component.setup;
    component.setup = (current, context) => {
        state = setup(current, context);

        return () => null;
    };
    const app = renderer.createApp({ render: () => h(component, props) });
    app.mount({ children: [] });

    return {
        app,
        props,
        state,
        requests,
        events,
        listeners,
        guard: (visit) => guard({ detail: { visit } }),
        removed: () => removed,
        focused: () => focused,
        Element,
    };
}
const settle = async () => {
    await Promise.resolve();
    await nextTick();
    await Promise.resolve();
};

test('late form loads cannot repopulate closed or switched contexts and unmount aborts pending work', async () => {
    const p = harness();
    assert.equal(p.requests.length, 1);
    p.props.open = false;
    await nextTick();
    assert.equal(p.requests[0].options.signal.aborted, true);
    p.requests[0].resolve(payload());
    await settle();
    assert.equal(p.state.payload.value, null);
    p.props.open = true;
    await nextTick();
    p.props.branchId = 10;
    await nextTick();
    assert.equal(p.requests[1].options.signal.aborted, true);
    p.requests[2].resolve(payload());
    await settle();
    assert.equal(p.state.payload.value, null);
    assert.match(p.state.loadError.value, /branch changed/);
    void p.state.load();
    p.app.unmount();
    assert.equal(p.requests.at(-1).options.signal.aborted, true);
    assert.equal(p.removed(), true);
    assert.equal(p.listeners.size, 0);
});

test('dirty dismissal retains the same loaded payload and Keep editing returns focus; processing refuses dismissal', async () => {
    const p = harness();
    p.requests[0].resolve(payload());
    await settle();
    const original = p.state.payload.value;
    p.state.formRef.value = {
        hasDraft: true,
        processing: false,
        commandKey: 'synthetic-key',
    };
    p.state.rememberField({ target: new p.Element() });
    p.state.requestClose();
    assert.equal(p.state.discardOpen.value, true);
    assert.equal(p.events.length, 0);
    const before = p.focused();
    p.state.keepEditing();
    p.state.restoreDraftFocus({ preventDefault() {} });
    await nextTick();
    assert.ok(p.focused() > before);
    assert.equal(p.state.payload.value, original);
    p.state.formRef.value.processing = true;
    p.state.requestClose();
    p.state.discard();
    assert.equal(p.events.length, 0);
    p.state.formRef.value.processing = false;
    p.state.discard();
    assert.deepEqual(p.events, [['open', false]]);
    p.app.unmount();
});

test('matching own command may navigate while unrelated dirty or processing visits are refused; success closes once', async () => {
    const p = harness();
    p.requests[0].resolve(payload());
    await settle();
    p.state.formRef.value = {
        hasDraft: true,
        processing: true,
        commandKey: 'synthetic-key',
    };
    assert.equal(
        p.guard({
            method: 'post',
            url: new URL('http://synthetic.test/registration'),
            data: { idempotency_key: 'synthetic-key' },
        }),
        undefined,
    );
    assert.equal(
        p.guard({
            method: 'post',
            url: new URL('http://synthetic.test/registration'),
            data: { idempotency_key: 'different' },
        }),
        false,
    );
    p.state.formRef.value.processing = false;
    assert.equal(
        p.guard({
            method: 'get',
            url: new URL('http://synthetic.test/patients'),
            data: {},
        }),
        false,
    );
    p.state.registered();
    p.state.registered();
    assert.deepEqual(p.events, [['registered']]);
    p.app.unmount();
});

test('loaded drafts become blocked after branch or authority change without silently replacing the command', async () => {
    const p = harness();
    p.requests[0].resolve(payload());
    await settle();
    const original = p.state.payload.value;
    p.props.branchId = 10;
    await nextTick();
    assert.equal(p.state.stale.value, true);
    assert.equal(p.state.payload.value, original);
    assert.equal(p.requests.length, 1);
    p.props.branchId = 9;
    p.props.canCreate = false;
    await nextTick();
    assert.equal(p.state.stale.value, true);
    assert.equal(p.state.payload.value.options.idempotencyKey, 'synthetic-key');
    p.app.unmount();
});

test('confirmation close restores a connected draft field or a current fallback, but never steals focus after discard', async () => {
    const p = harness();
    p.requests[0].resolve(payload());
    await settle();
    const field = new p.Element();
    p.state.rememberField({ target: field });
    let prevented = 0;
    const event = {
        preventDefault() {
            prevented++;
        },
    };
    const before = p.focused();
    p.state.restoreDraftFocus(event);
    assert.equal(p.focused(), before + 1);
    field.isConnected = false;
    p.state.restoreDraftFocus(event);
    await nextTick();
    assert.equal(p.focused(), before + 2);
    p.props.open = false;
    await nextTick();
    p.state.restoreDraftFocus(event);
    await nextTick();
    assert.equal(p.focused(), before + 2);
    assert.equal(prevented, 3);
    p.app.unmount();
});
