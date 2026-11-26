import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const source = readFileSync('resources/js/pages/Patient/Index.vue', 'utf8');
const script = compileScript(parse(source).descriptor, {
    id: 'patient-directory-workspace',
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
function harness() {
    const requests = [];
    const cleanups = [];
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        AbortController,
        document: { querySelector: () => ({ content: 'synthetic-csrf' }) },
        fetch: (url, options) =>
            new Promise((resolve, reject) =>
                requests.push({ url, options, resolve, reject }),
            ),
        require: (name) =>
            name === 'vue'
                ? {
                      ...require('vue'),
                      onBeforeUnmount: (fn) => cleanups.push(fn),
                  }
                : new Proxy({}, { get: () => ({}) }),
    });
    const state = module.exports.default.setup(
        { canCreate: false, canSeeMergeRequests: false },
        { expose: () => {} },
    );
    const resolve = (
        index,
        data = { data: [], total: 0, currentPage: 1, lastPage: 1 },
        ok = true,
    ) => requests[index].resolve({ ok, json: async () => data });

    return { state, requests, resolve, cleanups };
}
test('explicit search preserves masked POST contract and passport country', async () => {
    const { state, requests, resolve } = harness();
    state.form.search_type = 'passport';
    state.form.query = 'SYNTHETIC123';
    state.form.issuing_country_code = 'MY';
    assert.equal(requests.length, 0);
    const pending = state.search(2);
    assert.equal(requests[0].url, '/patients/search');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'synthetic-csrf');
    assert.deepEqual(JSON.parse(requests[0].options.body), {
        query: 'SYNTHETIC123',
        search_type: 'passport',
        issuing_country_code: 'MY',
        page: 2,
    });
    resolve(0, {
        data: [{ patientNumber: 'KP-SYN', maskedPhone: '***12' }],
        total: 1,
        currentPage: 2,
        lastPage: 2,
    });
    await pending;
    assert.equal(state.results.value[0].maskedPhone, '***12');
    assert.equal(state.currentPage.value, 2);
});
test('Clear aborts pending search, restores focus and refuses a late successful response', async () => {
    const { state, requests, resolve } = harness();
    let focused = 0;
    state.searchInput.value = { focus: () => focused++ };
    state.form.query = 'Synthetic';
    const pending = state.search();
    await state.clearSearch();
    assert.equal(requests.length, 1, 'Clear makes no new request');
    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(focused, 1);
    assert.equal(state.form.query, '');
    assert.equal(state.form.search_type, 'name');
    assert.equal(state.form.issuing_country_code, '');
    resolve(0, {
        data: [{ patientNumber: 'KP-OLD' }],
        total: 1,
        currentPage: 1,
        lastPage: 1,
    });
    await pending;
    assert.equal(state.results.value.length, 0);
    assert.equal(state.searched.value, false);
    assert.equal(state.loading.value, false);
    assert.equal(state.total.value, 0);
});
test('a superseded pagination reply cannot replace the latest results or loading state', async () => {
    const { state, requests, resolve } = harness();
    const old = state.search(2);
    const current = state.search(1);
    assert.equal(requests[0].options.signal.aborted, true);
    resolve(0, { message: 'Old refused request' }, false);
    await old;
    assert.equal(state.error.value, '');
    assert.equal(state.loading.value, true);
    resolve(1, {
        data: [{ patientNumber: 'KP-NEW' }],
        total: 1,
        currentPage: 1,
        lastPage: 1,
    });
    await current;
    assert.equal(state.results.value[0].patientNumber, 'KP-NEW');
    assert.equal(state.loading.value, false);
});
test('unmount aborts pending work and late failure cannot set an error', async () => {
    const { state, requests, cleanups } = harness();
    const pending = state.search();

    for (const cleanup of cleanups) {
cleanup();
}

    assert.equal(requests[0].options.signal.aborted, true);
    requests[0].reject(new Error('late response'));
    await pending;
    assert.equal(state.error.value, '');
});
