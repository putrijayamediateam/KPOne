import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { useForm } from '@inertiajs/vue3';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const helperModule = { exports: {} };
runInNewContext(
    transpile(readFileSync('resources/js/lib/patient-edit-summary.ts', 'utf8')),
    { exports: helperModule.exports },
);
const { patientEditChangedLabels } = helperModule.exports;
const source = readFileSync('resources/js/pages/Patient/Edit.vue', 'utf8');
const descriptor = parse(source).descriptor;
const compiled = compileScript(descriptor, { id: 'patient-edit-summary' });
const js = transpile(compiled.content);
const templateModule = { exports: {} };
runInNewContext(
    transpile(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'PatientEdit.vue',
            id: 'patient-edit-summary',
            compilerOptions: { bindingMetadata: compiled.bindings },
        }).code,
    ),
    { exports: templateModule.exports, require },
);
function harness(
    fullName = 'Synthetic Patient',
    lockVersion = 7,
    adult = false,
) {
    const patient = {
        patientNumber: 'KP-SYNTHETIC',
        canWhatsapp: false,
        identity: {
            fullName,
            dateOfBirth: adult ? '1990-01-01' : '2015-01-01',
            sex: 'unknown',
            nationalityCode: 'MY',
        },
        contact: { mobilePhone: '0123456789', email: 'synthetic@example.test' },
        address: {
            line1: 'Synthetic road',
            line2: '',
            postcode: '00000',
            city: 'Test city',
            state: 'Test state',
            countryCode: 'MY',
        },
        administrative: { lockVersion },
        guardian: adult
            ? null
            : {
                  full_name: 'Synthetic Guardian',
                  relationship: 'parent',
                  phone: '+12025550120',
                  phone_country: 'US',
                  email: null,
                  lock_version: 3,
              },
    };
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        crypto: webcrypto,
        require: (name) => {
            if (name === 'vue') {
                return require('vue');
            }

            if (name === '@inertiajs/vue3') {
                return {
                    useForm,
                    Head: () => null,
                    Link: () => null,
                    usePage: () => ({
                        props: { branchContext: { active: { id: 9 } } },
                    }),
                };
            }

            if (name === '@/lib/patient-edit-summary') {
                return helperModule.exports;
            }

            if (name === '@/lib/guardian-contact') {
                return { knownMinor: () => true };
            }

            if (name === '@/lib/patient-registration') {
                return { phoneError: () => '', focusInvalidField: () => {} };
            }

            return new Proxy({}, { get: () => () => null });
        },
    });
    const state = module.exports.default.setup(
        { patient, guardianToday: '2026-11-26', requirements: {} },
        { expose: () => {} },
    );

    return { state, patient, component: module.exports.default };
}
test('summary compares actual editable fields and guardian content, ignoring generated protocol metadata', () => {
    const { state } = harness();
    assert.equal(state.changedLabels.value.length, 0);
    state.form.full_name = 'Edited synthetic';
    state.form.guardian.email = 'guardian@example.test';
    assert.deepEqual(Array.from(state.changedLabels.value), [
        'Full name',
        'Guardian contact',
    ]);
    const snapshot = JSON.parse(JSON.stringify(state.form.data()));
    state.form.guardian.retry_key = 'another-key';
    state.form.guardian.lock_version = 99;
    state.form.lock_version = 99;
    state.form.expected_branch_id = 88;
    state.form.adult_confirmation_key = 'another-adult-key';
    assert.equal(patientEditChangedLabels(state.form, snapshot).length, 0);
});
test('changing phone country with the same local phone is visible; guardian country is also compared', () => {
    const { state } = harness();
    state.form.phone_country = 'SG';
    assert.deepEqual(Array.from(state.changedLabels.value), ['Phone country']);
    state.form.guardian.phone_country = 'MY';
    assert.deepEqual(Array.from(state.changedLabels.value), [
        'Phone country',
        'Guardian contact',
    ]);
});
test('Undo uses real Inertia form defaults, restores nested values and preserves every refusal error without a request', async () => {
    const { state } = harness();
    const original = JSON.parse(JSON.stringify(state.form.data()));
    let focused = 0;
    let sent = 0;
    state.editForm.value = {
        querySelector: () => ({ focus: () => focused++ }),
    };
    state.form.patch = () => sent++;
    state.form.full_name = 'Edited synthetic';
    state.form.guardian.full_name = 'Edited guardian';
    state.form.guardian.retry_key = 'new-key';
    state.form.adult_confirmed = true;
    state.form.setError({
        lock_version: 'Reload this record.',
        'guardian.retry_key': 'Command conflict.',
        expected_branch_id: 'Wrong branch.',
        email: 'Check email.',
    });
    const errors = { ...state.form.errors };
    await state.undoChanges();
    assert.deepEqual(JSON.parse(JSON.stringify(state.form.data())), original);
    assert.deepEqual({ ...state.form.errors }, errors);
    assert.equal(state.changedLabels.value.length, 0);
    assert.equal(focused, 1);
    assert.equal(sent, 0);
});
test('Undo cannot alter an in-flight form; separate Edit mounts use their newly loaded defaults', async () => {
    const { state } = harness();
    state.form.full_name = 'Pending change';
    state.form.processing = true;
    await state.undoChanges();
    assert.equal(state.form.full_name, 'Pending change');
    const next = harness('Newly loaded patient', 8).state;
    assert.equal(next.changedLabels.value.length, 0);
    assert.equal(next.form.lock_version, 8);
    assert.equal(next.form.full_name, 'Newly loaded patient');
});

test('retained hidden-guardian and branch refusals remain visible in the actual template after Undo', async () => {
    const { state, patient, component } = harness('Synthetic adult', 7, true);
    state.form.date_of_birth = '2015-01-01';
    state.form.guardian = {
        full_name: 'Draft guardian',
        relationship: 'parent',
        phone: '+12025550120',
        phone_country: 'US',
        email: null,
        lock_version: 0,
        retry_key: 'synthetic-key',
    };
    state.form.setError({
        'guardian.retry_key': 'Guardian command conflict.',
        expected_branch_id: 'Active branch changed.',
    });
    await state.undoChanges();
    assert.equal(state.form.date_of_birth, '1990-01-01');
    assert.equal(state.form.guardian, null);
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    {
                        ...component,
                        setup: () => state,
                        render: templateModule.exports.render,
                    },
                    { patient, requirements: {} },
                ),
        }),
    );
    assert.match(html, /role="alert"/);
    assert.match(html, /Guardian command conflict\./);
    assert.match(html, /Active branch changed\./);
    assert.match(html, /Review these errors before saving/);
});
