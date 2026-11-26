import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { useForm } from '@inertiajs/vue3';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const read = (file) => readFileSync(file, 'utf8');
const formSource = read(
    'resources/js/components/registration/RegistrationCreateForm.vue',
);
const pageSource = read('resources/js/pages/Registration/Create.vue');
const renderer = createRenderer({
    createElement: (tag) => ({ tag, children: [] }),
    createText: (text) => ({ text }),
    createComment: (text) => ({ text }),
    insert: (node, parent) => {
        node.parent = parent;
        parent.children ??= [];
        parent.children.push(node);
    },
    remove: (node) => {
        if (node.parent) {
            node.parent.children = node.parent.children.filter(
                (child) => child !== node,
            );
        }
    },
    setText: (node, text) => {
        node.text = text;
    },
    setElementText: (node, text) => {
        node.text = text;
    },
    parentNode: (node) => node.parent,
    nextSibling: (node) =>
        node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    patchProp: () => {},
});
function harness() {
    const requests = [];
    const timers = new Map();
    const instances = [];
    let timerId = 0;
    let focusCalls = 0;
    const props = reactive({
        options: {
            idempotencyKey: 'synthetic-command',
            branch: { id: 9, name: 'Synthetic branch' },
            doctors: [{ id: 1, name: 'Synthetic doctor', room: true }],
            panels: [],
            autoAssign: { available: true },
        },
        recentPatients: [],
        requirements: {},
        guardianToday: '2026-11-26',
    });
    const stubs = new Proxy({}, { get: () => () => null });
    const baseRequire = (name) => {
        if (name === 'vue') {
            return require(name);
        }

        if (name === '@inertiajs/vue3') {
            return { useForm, Head: () => null, Link: () => null };
        }

        if (name === '@/lib/patient-registration') {
            return {
                phoneError: () => '',
                identityError: () => '',
                focusInvalidField: () => {
                    focusCalls++;
                },
            };
        }

        if (name === '@/lib/guardian-contact') {
            return { knownMinor: () => false };
        }

        return stubs;
    };
    const load = (source, inline, customRequire = baseRequire) => {
        const module = { exports: {} };
        runInNewContext(
            transpile(
                compileScript(parse(source).descriptor, {
                    id: 'extraction-proof',
                    inlineTemplate: inline,
                }).content,
            ),
            {
                exports: module.exports,
                require: customRequire,
                crypto: webcrypto,
                AbortController,
                DOMException,
                document: {
                    querySelector: () => ({
                        content: 'synthetic-csrf',
                        focus: () => {
                            focusCalls++;
                        },
                    }),
                },
                setTimeout: (callback) => {
                    timers.set(++timerId, callback);

                    return timerId;
                },
                clearTimeout: (id) => timers.delete(id),
                fetch: (url, options) => {
                    requests.push({ url, options });

                    return url.startsWith('/registration/recommended-doctor')
                        ? Promise.resolve({
                              ok: true,
                              json: async () => ({ doctor: null }),
                          })
                        : new Promise(() => {});
                },
            },
        );

        return module.exports.default;
    };
    const form = load(formSource, false);
    const setup = form.setup;
    form.setup = (currentProps, context) => {
        const state = setup(currentProps, context);
        instances.push(state);

        return () => null;
    };
    const page = load(pageSource, true, (name) =>
        name === '@/components/registration/RegistrationCreateForm.vue'
            ? { default: form }
            : baseRequire(name),
    );
    const app = renderer.createApp({ render: () => h(page, props) });
    app.mount({ children: [] });

    return {
        app,
        props,
        instances,
        requests,
        timers,
        focusCalls: () => focusCalls,
    };
}

test('actual page mounts one unkeyed form and reactive preserved-state props retain draft and refusal', async () => {
    const { app, props, instances, requests } = harness();
    await nextTick();
    assert.equal(instances.length, 1);
    const state = instances[0];
    state.quick.value.full_name = 'Unsaved synthetic draft';
    state.form.setError('expected_branch_id', 'Previous save refused');
    props.options = {
        ...props.options,
        branch: { id: 10, name: 'Updated loaded branch' },
    };
    props.requirements = { requireEmail: true };
    await nextTick();
    assert.equal(instances.length, 1);
    assert.equal(state.quick.value.full_name, 'Unsaved synthetic draft');
    assert.equal(state.form.errors.expected_branch_id, 'Previous save refused');
    assert.equal(state.form.expected_branch_id, 9);
    assert.equal(state.props.options.branch.id, 10);
    assert.equal(state.props.requirements.requireEmail, true);
    assert.equal(
        requests.filter((request) =>
            request.url.startsWith('/registration/recommended-doctor'),
        ).length,
        1,
    );
    app.unmount();
});

test('actual form lifecycle aborts pending search and clears timer on navigation unmount', async () => {
    const { app, instances, requests, timers } = harness();
    const state = instances[0];
    state.patientQuery.value = 'Synthetic search';
    await nextTick();
    assert.equal(timers.size, 1);
    void state.searchPatients();
    const pending = requests.find(
        (request) => request.url === '/patients/search',
    );
    assert.equal(pending.options.signal.aborted, false);
    app.unmount();
    assert.equal(pending.options.signal.aborted, true);
    assert.equal(timers.size, 0);
});

test('actual submission remains single and preserves existing error focus callback', async () => {
    const { app, instances, focusCalls } = harness();
    const state = instances[0];
    const calls = [];
    state.selectPatient({
        patientNumber: 'KP-SYNTHETIC',
        fullName: 'Synthetic patient',
    });
    state.form.post = (url, options) => calls.push({ url, options });
    state.submit();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/registration');
    assert.equal(calls[0].options.preserveState, true);
    const before = focusCalls();
    calls[0].options.onError();
    assert.equal(focusCalls(), before + 1);
    assert.equal(state.form.patient_number, 'KP-SYNTHETIC');
    app.unmount();
    await nextTick();
});

test('real unmount and fresh mount create one fresh form rather than retaining abandoned draft', async () => {
    const first = harness();
    first.instances[0].quick.value.full_name = 'Abandoned synthetic draft';
    first.instances[0].form.setError('expected_branch_id', 'Refused earlier');
    first.app.unmount();
    const second = harness();
    await nextTick();
    assert.equal(second.instances.length, 1);
    assert.equal(second.instances[0].quick.value.full_name, '');
    assert.equal(second.instances[0].form.errors.expected_branch_id, undefined);
    assert.notEqual(second.instances[0].form, first.instances[0].form);
    second.app.unmount();
});
