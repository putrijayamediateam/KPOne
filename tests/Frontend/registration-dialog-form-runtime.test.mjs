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
function harness(presentation = 'dialog') {
    const requests = [];
    const events = [];
    const timers = new Map();
    const instances = [];
    let timerId = 0;
    let focusCalls = 0;
    const props = reactive({
        presentation,
        blocked: false,
        onRegistered: () => events.push('registered'),
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
    const app = renderer.createApp({ render: () => h(form, props) });
    app.mount({ children: [] });

    return {
        app,
        events,
        props,
        instances,
        requests,
        timers,
        focusCalls: () => focusCalls,
    };
}

test('dialog preserves the same command and draft after unknown transport or HTTP outcome', async () => {
    const proof = harness();
    const state = proof.instances[0];
    state.selectPatient({
        patientNumber: 'KP-SYNTHETIC',
        fullName: 'Synthetic patient',
    });
    const calls = [];
    state.form.post = (url, options) =>
        calls.push({ url, options, key: state.form.idempotency_key });
    state.submit();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.onNetworkError(), false);
    assert.match(state.submissionNotice.value, /unconfirmed/);
    assert.equal(state.form.patient_number, 'KP-SYNTHETIC');
    assert.equal(proof.events.length, 0);
    state.submit();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].key, calls[0].key);
    assert.equal(calls[1].options.onHttpException(), false);
    assert.equal(proof.events.length, 0);
    proof.app.unmount();
    await nextTick();
});

test('dialog success signal is restricted to an accepted registration result and guards blocked or processing submission', async () => {
    const proof = harness();
    const state = proof.instances[0];
    state.selectPatient({
        patientNumber: 'KP-SYNTHETIC',
        fullName: 'Synthetic patient',
    });
    const calls = [];
    state.form.post = (url, options) => calls.push({ url, options });
    state.form.processing = true;
    state.submit();
    assert.equal(calls.length, 0);
    state.form.processing = false;
    proof.props.blocked = true;
    await nextTick();
    state.submit();
    assert.equal(calls.length, 0);
    proof.props.blocked = false;
    await nextTick();
    state.submit();
    calls[0].options.onSuccess({ component: 'Auth/Login' });
    assert.equal(proof.events.length, 0);
    calls[0].options.onSuccess({ component: 'Registration/Index' });
    assert.deepEqual(proof.events, ['registered']);
    proof.app.unmount();
});

test('fullpage keeps original post options without modal interception', () => {
    const proof = harness('page');
    const state = proof.instances[0];
    state.selectPatient({
        patientNumber: 'KP-SYNTHETIC',
        fullName: 'Synthetic patient',
    });
    let options;
    state.form.post = (_, value) => {
        options = value;
    };
    state.submit();
    assert.deepEqual(Object.keys(options).sort(), ['onError', 'preserveState']);
    proof.app.unmount();
});

test('dialog dirty state includes unsaved quick draft even after switching to saved-patient mode', async () => {
    const proof = harness();
    const state = proof.instances[0];
    assert.equal(state.hasDraft.value, false);
    state.quick.value.full_name = 'Unsaved synthetic patient';
    state.changePatient();
    await nextTick();
    assert.equal(state.hasDraft.value, true);
    proof.app.unmount();
});

test('uncertain modal retries the exact dispatched payload and restores displayed draft despite changes while pending or after loss', async () => {
    const p = harness();
    const state = p.instances[0];
    state.selectPatient({
        patientNumber: 'KP-ORIGINAL',
        fullName: 'Original synthetic patient',
    });
    const calls = [];
    let transform = (data) => data;
    state.form.transform = (callback) => {
        transform = callback;

        return state.form;
    };
    state.form.post = (url, options) =>
        calls.push({
            url,
            options,
            payload: JSON.stringify(transform(state.form.data())),
        });
    state.submit();
    const original = calls[0].payload;
    state.form.processing = true;
    assert.equal(state.draftLocked.value, true);
    state.form.priority = 'urgent';
    state.selectedPatient.value = {
        patientNumber: 'KP-CHANGED',
        fullName: 'Changed during pending',
    };
    calls[0].options.onNetworkError();
    state.form.processing = false;
    assert.equal(state.draftLocked.value, true);
    assert.equal(state.form.priority, 'normal');
    assert.equal(
        state.selectedPatient.value.fullName,
        'Original synthetic patient',
    );
    state.form.patient_number = 'KP-DIFFERENT';
    state.form.idempotency_key = 'changed-key';
    state.form.priority = 'urgent';
    state.submit();
    assert.equal(calls[1].payload, original);
    assert.equal(state.form.patient_number, 'KP-ORIGINAL');
    assert.equal(state.form.idempotency_key, 'synthetic-command');
    assert.equal(state.form.priority, 'normal');
    p.app.unmount();
    await nextTick();
});
