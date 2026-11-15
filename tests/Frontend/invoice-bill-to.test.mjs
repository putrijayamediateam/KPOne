import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const source = read('resources/js/components/billing/InvoiceBillTo.vue');
const descriptor = parse(source).descriptor;
const js = ts.transpileModule(
    compileScript(descriptor, { id: 'notes' }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const require = createRequire(import.meta.url);
const flush = async () => {
    await Promise.resolve();
    await nextTick();
    await Promise.resolve();
};
const harness = (extra = {}) => {
    const props = reactive({
        context: {
            url: '/visits/SYN/billing/SYN/bill-to',
            branchId: 12,
            invoiceNumber: 'SYN-1',
            canManage: true,
        },
    });
    Object.assign(props, extra);
    const page = reactive({ props: { branchContext: { active: { id: 12 } } } });
    const requests = [],
        callbacks = {},
        cleanups = [],
        emitted = [];
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        crypto: { randomUUID },
        AbortController,
        URLSearchParams,
        document: { querySelector: () => ({ content: 'SYN-CSRF' }) },
        require: (name) => {
            if (name === 'vue') {
                return {
                    ...require('vue'),
                    onBeforeUnmount: (cb) => cleanups.push(cb),
                };
            }

            if (name === '@inertiajs/vue3') {
                return {
                    usePage: () => page,
                    router: {
                        on: (event, callback) => {
                            callbacks[event] = callback;

                            return () => delete callbacks[event];
                        },
                    },
                };
            }

            if (name === '@/lib/json-client') {
                return {
                    requestJson: (url, init) =>
                        new Promise((resolve, reject) =>
                            requests.push({ url, init, resolve, reject }),
                        ),
                };
            }

            if (name.startsWith('@/')) {
                return {};
            }

            return require(name);
        },
    });
    const api = module.exports.default.setup(props, {
        expose() {},
        emit: (name) => emitted.push(name),
    });

    return { props, page, requests, callbacks, api, cleanups, emitted };
};
const recipient = (name = 'Synthetic recipient') => ({
    party_type: 'company',
    display_name: name,
    company_name: null,
    address_line_1: null,
    address_line_2: null,
    city: null,
    state: null,
    postcode: null,
    country_code: null,
});
const response = (extra = {}) => ({
    current: null,
    data: [],
    version: 0,
    canManage: true,
    nextCursor: null,
    ...extra,
});
const ready = async (h, data = {}) => {
    h.requests[0].resolve(response(data));
    await flush();
};

test('Bill to compiles, uses bounded plain text and invoice-only print recipient, with no forbidden identifier or rich HTML controls', () => {
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'InvoiceBillTo.vue',
            id: 'bill-to',
        }).errors,
        [],
    );
    assert.match(source, /maxlength="field.max"/);
    assert.match(source, /No|Nothing is copied/);
    assert.doesNotMatch(
        source,
        /v-html|localStorage|sessionStorage|panel_id|patient_id/,
    );
    const recipientSource = read(
        'resources/js/components/billing/BillToRecipient.vue',
    );
    assert.match(recipientSource, /\{\{ recipient.display_name \}\}/);
    assert.doesNotMatch(recipientSource, /v-html/);

    for (const path of [
        'resources/js/components/billing/BillingWorkspace.vue',
        'resources/js/pages/Billing/Invoice.vue',
    ]) {
        assert.match(read(path), /InvoiceBillTo :context=/);
    }

    const print = read('resources/js/pages/Billing/Print.vue');
    assert.match(print, /v-if="!receipt && billTo"/);
    assert.match(print, /Patient:/);
    assert.doesNotMatch(print, /InvoiceNotes|billing.notes|note.body/);
});

test('summary loads privately and retries preserve the exact UUID/payload until confirmation; success reloads history and summary', async () => {
    const h = harness();
    const { api, requests } = h;
    assert.match(requests[0].url, /summary=1/);
    assert.equal(api.current.value, null);
    await ready(h, { current: recipient(), version: 3 });
    assert.equal(api.current.value.display_name, 'Synthetic recipient');
    api.show('change');
    requests[1].resolve(response({ current: recipient(), version: 3 }));
    await flush();
    api.draft.value.display_name = 'Synthetic changed';
    const save = api.save();
    const original = requests[2].init.body;
    assert.equal(JSON.parse(original).lock_version, 3);
    void api.save();
    assert.equal(requests.length, 3);
    requests[2].reject({ status: 503 });
    await save;
    const retry = api.save();
    assert.equal(requests[3].init.body, original);
    requests[3].resolve({ version: 4 });
    await flush();
    assert.equal(api.draft.value.display_name, '');
    assert.equal(api.mode.value, 'history');
    requests[4].resolve(
        response({
            current: recipient('Synthetic changed'),
            version: 4,
            data: [
                {
                    id: 'revision-4',
                    kind: 'changed',
                    recipient: recipient('Synthetic changed'),
                },
            ],
        }),
    );
    await retry;
    assert.equal(api.current.value.display_name, 'Synthetic changed');
    assert.equal(api.entries.value.length, 1);
});

test('stale save blocks retries until explicit refresh adopts the new version and reinitialises the draft', async () => {
    const h = harness();
    await ready(h);
    h.api.show('change');
    h.requests[1].resolve(response({ current: recipient(), version: 2 }));
    await flush();
    h.api.draft.value.display_name = 'Synthetic draft';
    const save = h.api.save();
    h.requests[2].reject({
        status: 422,
        payload: {
            errors: {
                lock_version: [
                    'Bill to changed. Refresh and review before saving.',
                ],
            },
        },
    });
    await save;
    assert.equal(h.api.stale.value, true);
    void h.api.save();
    assert.equal(h.requests.length, 3);
    const refresh = h.api.load();
    h.requests[3].resolve(
        response({ current: recipient('Synthetic concurrent'), version: 3 }),
    );
    await refresh;
    assert.equal(h.api.stale.value, false);
    assert.equal(h.api.draft.value.display_name, 'Synthetic concurrent');
    h.api.draft.value.display_name = 'Synthetic reviewed';
    const retry = h.api.save();
    assert.equal(JSON.parse(h.requests[4].init.body).lock_version, 3);
    h.requests[4].reject({ status: 403 });
    await retry;
    assert.equal(h.api.open.value, false);
    assert.equal(h.api.current.value, null);
    assert.deepEqual(Array.from(h.api.entries.value), []);
    assert.equal(h.api.draft.value.display_name, '');
});

test('branch switch and navigation discard summary, drafts and history and ignore late responses', async () => {
    const h = harness();
    const old = h.requests[0];
    h.page.props.branchContext.active.id = 13;
    await flush();
    assert.equal(old.init.signal.aborted, true);
    old.resolve(response({ current: recipient('Wrong branch') }));
    await flush();
    assert.equal(h.api.current.value, null);
    h.props.context = {
        url: '/visits/NEW/billing/NEW/bill-to',
        branchId: 13,
        invoiceNumber: 'SYN-2',
        canManage: true,
    };
    await flush();
    h.requests[1].resolve(response({ current: recipient('Right branch') }));
    await flush();
    h.api.show('change');
    const history = h.requests[2];
    h.callbacks.start();
    assert.equal(history.init.signal.aborted, true);
    history.resolve(response({ current: recipient('Late private recipient') }));
    await flush();
    assert.equal(h.api.current.value, null);
    assert.equal(h.api.open.value, false);
    assert.equal(h.api.draft.value.display_name, '');
    assert.equal(h.api.contextBlocked.value, true);
});

test('read-only Panel Officer can read current/history but cannot submit or clear; Doctor has no context or requests', async () => {
    const h = harness({
        context: {
            url: '/SYN/bill-to',
            branchId: 12,
            invoiceNumber: 'SYN-1',
            canManage: false,
        },
    });
    await ready(h, { current: recipient(), canManage: false });
    h.api.show('history');
    h.requests[1].resolve(
        response({
            current: recipient(),
            canManage: false,
            data: [{ id: 'revision', kind: 'set', recipient: recipient() }],
        }),
    );
    await flush();
    assert.equal(h.api.canManage.value, false);
    h.api.mode.value = 'change';
    void h.api.save();
    assert.equal(h.requests.length, 2);
    const doctor = harness({ context: null });
    assert.equal(doctor.requests.length, 0);
    assert.equal(doctor.api.current.value, null);
});

test('clear sends no recipient values, keeps immutable history and returns the patient fallback', async () => {
    const h = harness();
    await ready(h, { current: recipient(), version: 1 });
    h.api.show('change');
    h.requests[1].resolve(response({ current: recipient(), version: 1 }));
    await flush();
    h.api.prepareClear();
    const save = h.api.save();
    const payload = JSON.parse(h.requests[2].init.body);
    assert.equal(payload.operation, 'clear');
    assert.equal('recipient' in payload, false);
    h.requests[2].resolve({ version: 2 });
    await flush();
    h.requests[3].resolve(
        response({
            current: null,
            version: 2,
            data: [
                { id: 'clear', kind: 'cleared', recipient: null },
                { id: 'old', kind: 'set', recipient: recipient() },
            ],
        }),
    );
    await save;
    assert.equal(h.api.current.value, null);
    assert.equal(h.api.entries.value.length, 2);
    assert.equal(
        h.api.entries.value[1].recipient.display_name,
        'Synthetic recipient',
    );
});

test('closing during the post-save history reload refreshes the summary and cannot leave the previous recipient', async () => {
    const h = harness();
    await ready(h, { current: recipient('Synthetic original'), version: 1 });
    h.api.show('change');
    h.requests[1].resolve(
        response({ current: recipient('Synthetic original'), version: 1 }),
    );
    await flush();
    h.api.draft.value.display_name = 'Synthetic saved';
    const save = h.api.save();
    h.requests[2].resolve({ version: 2 });
    await flush();
    const history = h.requests[3];
    h.api.open.value = false;
    assert.equal(history.init.signal.aborted, true);
    assert.equal(h.api.current.value, null);
    assert.match(h.requests[4].url, /summary=1/);
    history.resolve(
        response({ current: recipient('Synthetic original'), version: 1 }),
    );
    await save;
    h.requests[4].resolve(
        response({ current: recipient('Synthetic saved'), version: 2 }),
    );
    await flush();
    assert.equal(h.api.current.value.display_name, 'Synthetic saved');
    assert.equal(h.api.entries.value.length, 0);
    assert.equal(h.api.draft.value.display_name, '');
});
