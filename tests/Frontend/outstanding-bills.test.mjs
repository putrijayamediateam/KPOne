import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/components/billing/OutstandingBills.vue');
const compiled = compileScript(parse(source).descriptor, { id: 'outstanding-test' });
const javascript = ts.transpileModule(compiled.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const groups = { branchId: 1, patient: { count: 12, totalSen: 36000, url: '/patient-bills' }, panel: { count: 12, totalSen: 12000, url: '/panel-bills' } };
const rows = (url) => ({ count: 12, totalSen: 36000, currentPage: 1, lastPage: 2, data: [{ invoiceNumber: 'SYN-001', amountSen: 3000, visitDate: '15 Oct', reviewRequired: false, url }] });
const harness = () => {
    const requests = [], visits = [];
    let start;
    const module = { exports: {} };
    const props = reactive({ groups });
    const page = reactive({ props: { branchContext: { active: { id: 1 } } } });
    runInNewContext(javascript, { exports: module.exports, AbortController, require: (name) => {
        if (name === '@inertiajs/vue3') {
return { usePage: () => page, router: { on: (event, callback) => {
 start = callback;

 return () => {}; 
}, visit: (url) => visits.push(url) } };
}

        if (name === '@/lib/json-client') {
return { requestJson: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) };
}

        if (name === 'vue') {
return { ...require('vue'), onBeforeUnmount: () => {} };
}

        if (name.startsWith('@/')) {
return {};
}

        return require(name);
    } });
    const api = module.exports.default.setup(props, { expose() {} });

    return { api, requests, visits, props, page, start: () => start({ detail: { visit: { url: new URL('http://synthetic.test/branch-context') } } }) };
};
const flush = async () => {
 await Promise.resolve(); await nextTick(); 
};

test('Confirm only navigates to a selected row returned by the authorized endpoint', async () => {
    const h = harness();
    h.api.show('patient');
    assert.equal(h.requests[0].url, '/patient-bills?page=1');
    h.api.selectedUrl.value = '/forged';
    h.api.confirm();
    assert.equal(h.visits.length, 0);
    h.requests[0].resolve(rows('/visits/SYN-001/billing'));
    await flush();
    h.api.selectedUrl.value = '/forged';
    h.api.confirm();
    assert.equal(h.visits.length, 0);
    h.api.selectedUrl.value = '/visits/SYN-001/billing';
    h.api.confirm();
    assert.deepEqual(h.visits, ['/visits/SYN-001/billing']);
    assert.doesNotMatch(source, /router\.(post|put|patch|delete)|useForm/);
});

test('paging clears selection; closed and branch-switched dialogs reject late responses', async () => {
    const h = harness();
    h.api.show('patient');
    h.requests[0].resolve(rows('/first'));
    await flush();
    h.api.selectedUrl.value = '/first';
    const second = h.api.load(2);
    assert.equal(h.api.selectedUrl.value, '');
    assert.equal(h.api.result.value, null);
    assert.equal(h.requests[1].url, '/patient-bills?page=2');
    h.start();
    assert.equal(h.requests[1].options.signal.aborted, true);
    h.requests[1].resolve(rows('/late'));
    await second;
    await flush();
    assert.equal(h.api.result.value, null);
    assert.equal(h.api.open.value, false);
    assert.equal(h.api.blocked.value, true);
    h.api.selectedUrl.value = '/late';
    h.api.confirm();
    assert.equal(h.visits.length, 0);
});

test('CA projection exposes one group and endpoint failure cannot leave a confirmable selection', async () => {
    const h = harness();
    h.props.groups = { branchId: 1, patient: groups.patient, panel: null };
    await flush();
    assert.deepEqual(Array.from(h.api.groups.value, (g) => g.key), ['patient']);
    h.api.show('patient');
    h.requests[0].reject(new Error('Refused'));
    await flush();
    assert.equal(h.api.selectedUrl.value, '');
    assert.equal(h.api.result.value, null);
    assert.match(h.api.error.value, /could not be loaded/);
    h.page.props.branchContext.active.id = 2;
    await flush();
    assert.equal(h.api.blocked.value, true);
    assert.equal(h.api.open.value, false);
    assert.match(read('resources/js/pages/Billing/Show.vue'), /<OutstandingBills :groups="billing.outstanding"/);
    assert.match(read('resources/js/pages/Billing/Invoice.vue'), /<OutstandingBills :groups="invoice.outstanding"/);
});
