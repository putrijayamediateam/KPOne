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
function harness(presentation = 'full-page') {
    const requests = [];
    const timers = new Map();
    const instances = [];
    let timerId = 0;
    let focusCalls = 0;
    const props = reactive({
        presentation,
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
                    const request = { url, options };
                    requests.push(request);

                    return url.startsWith('/registration/recommended-doctor')
                        ? Promise.resolve({
                              ok: true,
                              json: async () => ({ doctor: null }),
                          })
                        : new Promise((resolve, reject) => {
                              request.resolve = resolve;
                              request.reject = reject;
                          });
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
        props,
        instances,
        requests,
        timers,
        focusCalls: () => focusCalls,
    };
}

const patient = {
    patientNumber: 'KP-SYNTHETIC',
    fullName: 'Synthetic patient',
};

test('selected Find and current New are no-ops and New cannot replace a selected patient', async () => {
    const p = harness();
    const s = p.instances[0];
    s.selectPatient(patient);
    await nextTick();
    const baseline = p.requests.length;
    s.choosePatientMode('find');
    s.choosePatientMode('new');
    assert.equal(s.selectedPatient.value.patientNumber, patient.patientNumber);
    assert.equal(s.form.patient_number, patient.patientNumber);
    assert.equal(s.quickMode.value, false);
    assert.equal(p.requests.length, baseline);
    s.changePatient();
    s.choosePatientMode('new');
    s.quick.value.full_name = 'Retained draft';
    const focus = p.focusCalls();
    s.choosePatientMode('new');
    assert.equal(s.quick.value.full_name, 'Retained draft');
    assert.equal(p.focusCalls(), focus);
    p.app.unmount();
});

test('New has exactly the existing recent/results/loading and short/error availability', async () => {
    const p = harness();
    const s = p.instances[0];
    assert.equal(s.canStartQuickCreate.value, true);
    s.patientResults.value = [patient];
    assert.equal(s.canStartQuickCreate.value, false);
    s.choosePatientMode('new');
    assert.equal(s.quickMode.value, false);
    s.patientQuery.value = 'x';
    await nextTick();
    assert.equal(s.canStartQuickCreate.value, true);
    s.patientSearchError.value = 'Controlled error';
    assert.equal(s.canStartQuickCreate.value, true);
    s.searching.value = true;
    assert.equal(s.canStartQuickCreate.value, false);
    s.choosePatientMode('new');
    assert.equal(s.quickMode.value, false);
    s.searching.value = false;
    const baseline = p.requests.length;
    s.choosePatientMode('new');
    assert.equal(s.quickMode.value, true);
    assert.equal(p.requests.length, baseline);
    p.app.unmount();
});

test('Find restores existing recent/focus behavior and preserves quick draft until genuinely eligible New', async () => {
    const p = harness();
    const s = p.instances[0];
    s.choosePatientMode('new');
    s.quick.value.full_name = 'Draft survives mode';
    s.quick.value.guardian = {
        full_name: 'Synthetic guardian',
        relationship: 'parent',
    };
    p.props.recentPatients = [patient];
    await nextTick();
    const baseline = p.requests.length;
    const focus = p.focusCalls();
    s.choosePatientMode('find');
    await nextTick();
    assert.equal(s.quickMode.value, false);
    assert.equal(s.patientQuery.value, '');
    assert.equal(
        s.patientResults.value[0].patientNumber,
        patient.patientNumber,
    );
    assert.equal(s.canStartQuickCreate.value, false);
    assert.ok(p.focusCalls() > focus);
    s.choosePatientMode('new');
    assert.equal(s.quickMode.value, false);
    s.patientQuery.value = 'x';
    await nextTick();
    s.choosePatientMode('new');
    await nextTick();
    assert.equal(s.quickMode.value, true);
    assert.equal(s.quick.value.full_name, 'Draft survives mode');
    assert.equal(s.quick.value.guardian.full_name, 'Synthetic guardian');
    assert.equal(p.requests.length, baseline);
    p.app.unmount();
});

test('processing and unconfirmed dialog state synchronously prevent either mode switch', async () => {
    const p = harness('dialog');
    const s = p.instances[0];
    s.form.processing = true;
    assert.equal(s.draftLocked.value, true);
    s.choosePatientMode('new');
    assert.equal(s.quickMode.value, false);
    s.form.processing = false;
    s.choosePatientMode('new');
    s.submissionNotice.value = 'Unconfirmed original command';
    assert.equal(s.draftLocked.value, true);
    s.choosePatientMode('find');
    assert.equal(s.quickMode.value, true);
    assert.equal(s.submissionNotice.value, 'Unconfirmed original command');
    p.app.unmount();
});
