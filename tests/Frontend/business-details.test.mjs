import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/pages/BusinessDetails/Index.vue', import.meta.url), 'utf8');
const descriptor = parse(source).descriptor;
const js = ts.transpileModule(compileScript(descriptor, { id: 'biz01' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const harness = () => {
    const props = reactive({ canEdit: true, letterhead: { branchId: 1, branchName: 'Synthetic Branch', organisationVersion: 0, branchVersion: 0, logo: null,
        organisation: { legal_name: 'Synthetic Legal', registration_type: null, registration_number: null },
        details: { clinic_name: 'Synthetic Clinic', address_line_1: '', address_line_2: null, postcode: '', city: '', state: '', country: '', phone: null, email: null } } });
    const calls = [], callbacks = {}, revoked = [];
    const module = { exports: {} };
    const useForm = (initial) => {
        let defaults = { ...initial };
        const form = reactive({ ...initial, processing: false, errors: {} });
        form.defaults = (value) => {
 defaults = { ...value };
};
        form.reset = () => Object.assign(form, defaults);
        form.clearErrors = () => {
 form.errors = {};
};
        form.post = (url, options) => {
 form.processing = true; calls.push({ url, options, payload: Object.fromEntries(Object.keys(defaults).map((key) => [key, form[key]])) });
};

        return form;
    };
    runInNewContext(js, { exports: module.exports, crypto: { randomUUID }, URL: { createObjectURL: () => 'blob:synthetic', revokeObjectURL: (url) => revoked.push(url) }, require: (name) => {
        if (name === '@inertiajs/vue3') {
return { useForm, router: { on: (event, callback) => {
 callbacks[event] = callback;

 return () => {};
}, get: () => {} } };
}

        if (name === 'vue') {
return { ...require('vue'), onUnmounted: () => {} };
}

        if (name.startsWith('@/')) {
return {};
}

        return require(name);
    } });
    const api = module.exports.default.setup(props, { expose() {} });

    return { api, props, calls, callbacks, revoked };
};

test('Business details retries retain the key and draft, guard busy submits, and adopt returned versions after success', async () => {
    const h = harness();
    h.api.form.clinic_name = 'Synthetic Edited Clinic';
    h.api.save();
    h.api.save();
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].options.forceFormData, true);
    const key = h.calls[0].payload.idempotency_key;
    h.api.form.processing = false; // Refused request finishes without onSuccess.
    h.api.form.errors = { email: 'Invalid email' };
    h.api.save();
    assert.equal(h.calls[1].payload.idempotency_key, key);
    assert.equal(h.calls[1].payload.clinic_name, 'Synthetic Edited Clinic');
    h.props.letterhead.organisationVersion = 1;
    h.props.letterhead.branchVersion = 1;
    h.props.letterhead.details.clinic_name = 'Synthetic Edited Clinic';
    await h.calls[1].options.onSuccess();
    h.api.form.processing = false;
    assert.equal(h.api.form.organisation_lock_version, 1);
    assert.equal(h.api.form.branch_lock_version, 1);
    assert.notEqual(h.api.form.idempotency_key, key);
    h.api.save();
    assert.equal(h.calls[2].payload.branch_lock_version, 1);
});

test('logo selection is only pending until save; Cancel discards it and revokes the preview URL', () => {
    const h = harness();
    const file = { name: 'synthetic.png' };
    h.api.chooseLogo({ target: { files: [file] } });
    assert.equal(h.api.form.logo.name, file.name);
    assert.equal(h.api.logoUrl.value, 'blob:synthetic');
    assert.equal(h.calls.length, 0);
    h.api.adopt();
    assert.equal(h.api.form.logo, null);
    assert.equal(h.api.logoUrl.value, '');
    assert.deepEqual(h.revoked, ['blob:synthetic']);
});

test('branch switching clears drafts and blocks posting until the new branch props are adopted', async () => {
    const h = harness();
    h.api.form.clinic_name = 'Synthetic Old Branch Draft';
    h.api.form.email = 'old@branch.test';
    h.callbacks.start({ detail: { visit: { url: new URL('http://synthetic.test/branch-context') } } });
    h.api.save();
    assert.equal(h.calls.length, 0);
    assert.equal(h.api.form.clinic_name, '');
    assert.equal(h.api.form.email, '');
    h.props.letterhead.branchId = 2;
    h.props.letterhead.details.clinic_name = 'Synthetic New Branch';
    await nextTick();
    assert.equal(h.api.blocked.value, false);
    h.api.save();
    assert.equal(h.calls[0].payload.expected_branch_id, 2);
    assert.equal(h.calls[0].payload.clinic_name, 'Synthetic New Branch');
    assert.notEqual(h.calls[0].payload.email, 'old@branch.test');
});

test('view-only users cannot submit; fields and errors are accessible without deferred feature controls', () => {
    const h = harness();
    h.props.canEdit = false;
    h.api.save();
    assert.equal(h.calls.length, 0);
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'Index.vue', id: 'biz01-template' }).errors, []);
    assert.match(source, /:disabled="!canEdit \|\| form.processing \|\| blocked"/);
    assert.match(source, /v-if="canEdit"/);
    assert.match(source, /accept="image\/jpeg,image\/png"/);
    assert.match(source, /aria-describedby="logo-error"/);
    assert.match(source, /role="alert"/);
    assert.match(source, /href="\/clinic-management"/);
    assert.match(source, /← Clinic management/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|GST|SST|Tax settings|Account number|Footer|e-Invoice/);
});
