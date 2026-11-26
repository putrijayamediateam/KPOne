import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const compile = (path, inlineTemplate = false) => ts.transpileModule(compileScript(parse(readFileSync(path, 'utf8')).descriptor, { id: path, inlineTemplate }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const code = compile('resources/js/components/patient/FamilyLinksCard.vue');
const harness = () => {
    const calls = [];
    const page = reactive({ props: { branchContext: { active: { id: 1 } } } });
    const props = reactive({ patientNumber: 'KP-CURRENT', patientName: 'Synthetic Current' });
    const module = { exports: {} };
    const focus = [];
    runInNewContext(code, {
        exports: module.exports, AbortController, crypto: { randomUUID },
        document: { querySelector: () => ({ content: 'synthetic-csrf' }), getElementById: (id) => ({ focus: () => focus.push(id) }) },
        fetch: (url, init) => new Promise((resolve, reject) => calls.push({ url, init, resolve: (data, status = 200) => resolve({ ok: status < 400, status, json: async () => data }), reject })),
        require: (name) => {
            if (name === '@inertiajs/vue3') return { usePage: () => page };
            if (name === 'vue') return { ...require('vue'), onBeforeUnmount: () => {} };
            if (name.startsWith('@/')) return {};
            return require(name);
        },
    });
    const api = module.exports.default.setup(props, { expose() {} });
    return { api, calls, props, page, focus };
};
const settle = async () => { await Promise.resolve(); await Promise.resolve(); await nextTick(); };
const ready = async () => {
    const harnessed = harness();
    harnessed.calls[0].resolve({ links: [], canAdd: true });
    await settle();
    return harnessed;
};

test('family search starts empty and only runs on an explicit search; old branch results cannot reappear', async () => {
    const { api, calls, page } = await ready();
    await api.start();
    assert.equal(calls.length, 1);
    assert.equal(api.query.value, '');
    api.query.value = 'Synthetic';
    await nextTick();
    assert.equal(calls.length, 1);
    const search = api.search();
    assert.equal(calls[1].url, '/patients/search');
    assert.equal(JSON.parse(calls[1].init.body).search_type, 'name');
    page.props.branchContext.active.id = 2;
    await nextTick();
    assert.equal(calls[1].init.signal.aborted, true);
    calls[2].resolve({ links: [], canAdd: false });
    calls[1].resolve({ data: [{ patientNumber: 'KP-OLD', fullName: 'Must not appear' }] });
    await search;
    await settle();
    assert.equal(api.results.value.length, 0);
    assert.equal(api.editor.value, false);
    assert.equal(api.directory.value.canAdd, false);
});

test('retry preserves the original key and blocks a changed draft after a lost response', async () => {
    const { api, calls } = await ready();
    await api.start();
    await api.select({ patientNumber: 'KP-RELATIVE', fullName: 'Synthetic Relative' });
    api.relationship.value = 'parent_of';
    const first = api.submit();
    const payload = JSON.parse(calls[1].init.body);
    assert.equal(payload.operation, 'link');
    assert.equal(payload.other_patient_number, 'KP-RELATIVE');
    assert.equal(payload.expected_branch_id, 1);
    calls[1].reject(new Error('Response lost'));
    await first;
    assert.equal(api.selected.value.patientNumber, 'KP-RELATIVE');
    assert.match(api.draftError.value, /could not confirm whether this change was saved/);
    api.relationship.value = 'child_of';
    await api.submit();
    assert.equal(calls.length, 2);
    assert.match(api.draftError.value, /earlier attempt may have been saved/);
    api.relationship.value = 'parent_of';
    const retry = api.submit();
    assert.equal(JSON.parse(calls[2].init.body).idempotency_key, payload.idempotency_key);
    calls[2].resolve({ links: [], canAdd: true });
    await retry;
    assert.equal(api.editor.value, false);
});

test('suppressed removal sends immutable link identity, retained relationship and current version; denial clears identities', async () => {
    const { api, calls } = await ready();
    api.askRemove({ id: 7, lockVersion: 4, active: true, suppressed: true, relationshipCode: 'parent_of' });
    const remove = api.submit(true);
    const body = JSON.parse(calls[1].init.body);
    assert.equal(body.link_id, 7);
    assert.equal(body.lock_version, 4);
    assert.equal(body.operation, 'unlink');
    assert.equal('other_patient_number' in body, false);
    assert.equal(body.relationship_code, 'parent_of');
    calls[1].resolve({}, 403);
    await remove;
    assert.equal(api.directory.value, null);
    assert.equal(api.removeTarget.value, null);
});

test('a changed search aborts stale matches without automatic lookup', async () => {
    const { api, calls } = await ready();
    api.query.value = 'First';
    await nextTick();
    const pending = api.search();
    api.query.value = 'Second';
    await nextTick();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].init.signal.aborted, true);
    calls[1].resolve({ data: [{ patientNumber: 'KP-STALE', fullName: 'Stale' }] });
    await pending;
    assert.equal(api.results.value.length, 0);
});

test('Consultation renders relative-to-current direction and only the allowlisted identity fields', async () => {
    const module = { exports: {} };
    runInNewContext(compile('resources/js/components/clinical/FamilyWaitingCard.vue', true), {
        exports: module.exports,
        require: (name) => name === '@inertiajs/vue3' ? { Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) } } : require(name),
    });
    const family = { rows: [{ relationshipLabel: 'Child of', name: 'Synthetic Relative', patientNumber: 'KP-REL', queueLabel: 'A001', url: '/patients/KP-REL', condition: 'SECRET-CONDITION', phone: 'SECRET-PHONE' }], recordUrl: '/patients/KP-CURRENT#family-links' };
    const html = await renderToString(createSSRApp(module.exports.default, { family }));
    assert.match(html, /Child of this patient/);
    assert.match(html, /A001/);
    assert.doesNotMatch(html, /SECRET|Call In|Start consultation/);
    const empty = await renderToString(createSSRApp(module.exports.default, { family: { rows: [], recordUrl: null } }));
    assert.match(empty, /No linked relatives waiting in your permitted queue today/);
    assert.doesNotMatch(empty, /href=/);
});


test('an old directory denial cannot close the new context editor', async () => {
    const { api, calls, page } = harness();
    page.props.branchContext.active.id = 2;
    await nextTick();
    calls[1].resolve({ links: [], canAdd: true });
    await settle();
    await api.start();
    calls[0].resolve({}, 403);
    await settle();
    assert.equal(api.editor.value, true);
    assert.equal(api.directory.value.canAdd, true);
});
