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
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const source = read('resources/js/components/billing/BillingWorkspace.vue');
const descriptor = parse(source).descriptor;
const javascript = transpile(compileScript(descriptor, { id: 'billing-dialog-test' }).content);
const money = { exports: {} };
runInNewContext(transpile(read('resources/js/types/billing.ts')), { exports: money.exports });
const harness = (manage = false) => {
    const requests = [], callbacks = {};
    const props = reactive({ manage, billing: {
        visit: { visitNumber: 'SYN-001', status: 'registered', lockVersion: 3 },
        invoice: { publicId: 'SYN-INVOICE', lockVersion: 7, state: { total: 4000, self_pay: 0, panel: 0, deferred: 0, due_now: 4000 }, lines: [] },
        methods: [], panels: [], can: { pay: true, panelPropose: true, deferPropose: true, reverse: true },
    } });
    const page = reactive({ props: { branchContext: { active: { id: 12 } } } });
    const module = { exports: {} };
    runInNewContext(javascript, { exports: module.exports, crypto: { randomUUID }, require: (name) => {
        if (name === '@inertiajs/vue3') {
return { usePage: () => page, router: {
            on: (event, callback) => {
 callbacks[event] = callback;

 return () => {};
},
            post: (url, payload, options) => requests.push({ url, payload, options }),
        } };
}

        if (name === '@/types/billing') {
return money.exports;
}

        if (name === 'vue') {
return { ...require('vue'), onUnmounted: () => {} };
}

        if (name.startsWith('@/') || name === '@lucide/vue') {
return {};
}

        return require(name);
    } });
    const api = module.exports.default.setup(props, { expose() {} });

    return { api, props, page, requests, callbacks };
};

test('dialog Cancel keeps exact payment draft and idempotency key; retry is single-flight and success clears it', async () => {
    const h = harness(true);
    h.api.openEntry('payment');
    h.api.form.amount = '0.29';
    h.api.form.responsibility_amount = '30';
    h.api.form.method = 'cash';
    h.api.form.reference = 'SYN-REF';
    h.api.updateDialog('payment', false);
    assert.equal(h.api.form.amount, '0.29');
    h.api.openEntry('payment');
    h.api.payment();
    assert.equal(h.requests[0].payload.amount_sen, '29');
    assert.equal(h.requests[0].payload.return_to, 'payments');
    assert.equal(h.requests[0].payload.expected_branch_id, 12);
    assert.equal(h.requests[0].payload.lock_version, 7);
    h.api.updateDialog('payment', false);
    h.api.payment();
    assert.equal(h.api.paymentOpen.value, true);
    assert.equal(h.requests.length, 1);
    h.requests[0].options.onError({ invoice: 'Synthetic stale version' });
    h.props.billing = { ...h.props.billing, invoice: { ...h.props.billing.invoice } };
    await nextTick();
    assert.equal(h.api.form.amount, '0.29');
    assert.equal(h.api.form.reference, 'SYN-REF');
    h.requests[0].options.onFinish();
    h.api.payment();
    assert.equal(h.requests[1].payload.idempotency_key, h.requests[0].payload.idempotency_key);
    h.requests[1].options.onSuccess();
    h.requests[1].options.onFinish();
    assert.equal(h.api.paymentOpen.value, false);
    assert.equal(h.api.form.amount, '');
    assert.equal(h.api.form.responsibility_amount, '');
    assert.equal(h.api.form.reference, '');
    h.api.form.amount = '1';
    h.api.form.method = 'cash';
    h.api.payment();
    assert.notEqual(h.requests[2].payload.idempotency_key, h.requests[1].payload.idempotency_key);
});

test('Panel and pay-later use responsibility fields and preserve reasons/approval version without pretending to be a receipt', () => {
    const h = harness();
    h.api.openEntry('panel');
    h.api.form.amount = '3';
    h.api.form.responsibility_amount = '30.25';
    h.api.form.reason = 'Synthetic evidence';
    h.api.form.panel_id = '8';
    h.api.updateDialog('responsibility', false);
    h.api.openEntry('panel');
    assert.equal(h.api.form.reason, 'Synthetic evidence');
    h.api.propose('panel');
    assert.equal(h.requests[0].payload.amount_sen, '3025');
    assert.equal(h.requests[0].payload.reason, 'Synthetic evidence');
    assert.equal(h.requests[0].payload.panel_id, '8');
    assert.equal(h.requests[0].payload.return_to, 'billing');
    assert.equal(h.requests[0].payload.idempotency_key, undefined); // Known follow-up; no new proposal API contract.
    h.requests[0].options.onFinish();
    h.api.approve('panel', { publicId: 'SYN-PROPOSAL', lockVersion: 4 });
    assert.equal(h.requests[1].payload.proposal_lock_version, 4);
    assert.equal(h.requests[1].payload.amount_sen, undefined);
});

test('branch switch closes every dialog and clears amounts, references, reasons and pending selection', async () => {
    const h = harness(true);
    h.api.openEntry('payment');
    h.api.form.amount = '10';
    h.api.form.responsibility_amount = '20';
    h.api.form.reference = 'SYN';
    h.api.form.reason = 'Synthetic reason';
    h.callbacks.start({ detail: { visit: { url: new URL('https://synthetic.test/branch-context') } } });
    assert.equal(h.api.contextCleared.value, true);
    assert.equal(h.api.paymentOpen.value, false);
    assert.equal(h.api.responsibilityOpen.value, false);
    assert.equal(h.api.form.amount, '');
    assert.equal(h.api.form.responsibility_amount, '');
    assert.equal(h.api.form.reference, '');
    assert.equal(h.api.form.reason, '');
    h.api.payment();
    assert.equal(h.requests.length, 0);
    h.page.props.branchContext.active.id = 13;
    await nextTick();
    assert.equal(h.api.form.panel_id, '');
});

test('denied actions cannot open dialogs and Manage compiles with completion reserved for Billing', () => {
    const h = harness(true);
    h.props.billing.can.pay = false;
    h.props.billing.can.panelPropose = false;
    h.props.billing.can.reverse = false;
    h.api.openEntry('payment');
    h.api.openEntry('panel');
    h.api.openReversal({ status: 'posted' });
    assert.equal(h.api.paymentOpen.value, false);
    assert.equal(h.api.responsibilityOpen.value, false);
    assert.equal(h.api.reversalOpen.value, false);
    assert.deepEqual(compileTemplate({ id: 'billing-dialogs', filename: 'Show.vue', source: descriptor.template.content }).errors, []);
    assert.match(source, /v-if="!manage && billing.can.complete"/);
    assert.match(read('resources/js/pages/Billing/Payments.vue'), /<BillingWorkspace :billing="billing" manage/);
    assert.match(source, /@escape-key-down="busy && \$event.preventDefault\(\)"/);
});


test('financial summary uses one fixed-row grid with uniform cells on Billing and Manage', () => {
    const summary = descriptor.template.content.match(/<dl\b[^>]*data-testid="billing-summary-grid"[^>]*>[\s\S]*?<\/dl>/)?.[0];
    assert.ok(summary, 'one shared summary grid must exist');
    assert.match(summary, /grid auto-rows-\[44px\]/);
    assert.match(summary, /sm:grid-cols-2 lg:grid-cols-3/);
    assert.equal((summary.match(/class="billing-summary-row"/g) ?? []).length, 4); // Total, repeated responsibility cells, Outstanding, Due Now.
    assert.doesNotMatch(summary, /border-[bt]|p[tyb]-|v-if="!manage"/);
    const rowStyle = source.match(/\.billing-summary-row\s*\{([^}]+)\}/)?.[1];
    assert.match(rowStyle, /height: 44px/);
    assert.match(rowStyle, /align-items: center/);
    assert.match(rowStyle, /border-bottom: 1px solid var\(--border\)/);
    assert.match(rowStyle, /line-height: 1.25rem/);
});
