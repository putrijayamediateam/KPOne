import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { effectScope, nextTick, reactive, ref } from 'vue';

const require = createRequire(import.meta.url);
const root = new URL('../../', import.meta.url);
const transpile = (s) =>
    ts.transpileModule(s, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const source = readFileSync(
    new URL(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
        root,
    ),
    'utf8',
);
const code = transpile(
    compileScript(parse(source).descriptor, { id: 'po-metadata-actual' })
        .content,
);
const row = (extra = {}) => ({
    publicId: 'order-a',
    number: 'SYN-PO-A',
    status: 'draft',
    lockVersion: 1,
    supplierPublicId: 'supplier-a',
    destinationPublicId: 'location-a',
    expectedDeliveryDate: '2026-12-01',
    supplierReference: 'SYN-QUOTE',
    lines: [
        {
            publicId: 'line-a',
            skuPublicId: 'sku-a',
            orderedQuantity: '5',
            receivedQuantity: '0',
        },
    ],
    ...extra,
});
function setup(initial = row()) {
    const requests = [];
    const inert = {
        useRemember: (v) => ref(v),
        router: Object.fromEntries(
            ['post', 'patch', 'put'].map((method) => [
                method,
                (url, data, options) =>
                    requests.push({ method, url, data, options }),
            ]),
        ),
    };
    inert.router.restore = () => undefined;
    inert.router.remember = () => {};
    const cache = new Map();
    const loader = (name) => {
        if (name === '@inertiajs/vue3') {
            return inert;
        }

        if (name.startsWith('@/lib/') || name.startsWith('@/types/')) {
            if (cache.has(name)) {
                return cache.get(name);
            }

            const exports = {};
            cache.set(name, exports);
            runInNewContext(
                transpile(
                    readFileSync(
                        new URL(`resources/js/${name.slice(2)}.ts`, root),
                        'utf8',
                    ),
                ),
                {
                    exports,
                    require: loader,
                    crypto: { randomUUID },
                    URL,
                    URLSearchParams,
                    window: undefined,
                },
            );

            return exports;
        }

        return name.startsWith('@/') || name === '@lucide/vue'
            ? new Proxy({}, { get: () => ({}) })
            : require(name);
    };
    const exports = {};
    runInNewContext(code, { exports, require: loader, crypto: { randomUUID } });
    const props = reactive({
        operations: {
            expectedBranchId: 1,
            permissions: {},
            purchaseOrders: [initial],
            stockRequests: [],
            stocktakes: [],
            adjustments: [],
            suppliers: [],
            locations: [],
            skus: [],
            batches: [],
        },
        receiptMemoryContext: {
            version: 2,
            organisationPublicId: 'a'.repeat(64),
            actorPublicId: 'b'.repeat(64),
            sessionNonce: '00000000-0000-4000-8000-000000000901',
            branchPublicId: 'c'.repeat(64),
        },
    });
    const scope = effectScope();
    const state = scope.run(() =>
        exports.default.setup(props, { expose() {} }),
    );

    return { props, state, requests, scope };
}
test('actual parent busy aggregation keys instance tokens so old cleanup cannot unlock a new receiving instance', () => {
    const m = setup();
    const first = Symbol('old'),
        second = Symbol('new');
    const event = (token, processing, branchId = 1, orderId = 'order-a') =>
        m.state.receivingProcessingChanged({
            token,
            processing,
            branchId,
            orderId,
        });
    event(first, true);
    event(second, true);
    event(first, false);
    assert.equal(m.state.purchaseOrderBusy.value, true);
    event(second, false);
    assert.equal(m.state.purchaseOrderBusy.value, false);
    event(first, true, 2);
    assert.equal(m.state.purchaseOrderBusy.value, false);
    event(first, false, 2);
    event(second, true, 1, 'gone');
    assert.equal(m.state.purchaseOrderBusy.value, false);
    m.state.busy.value = true;
    assert.equal(m.state.purchaseOrderBusy.value, true);
    m.scope.stop();
});
test('actual original confirmation retains order identity and version after row refresh', async () => {
    const m = setup();
    const original = m.props.operations.purchaseOrders[0];
    m.state.confirmPurchaseOrderTransition(original, 'approve');
    m.props.operations.purchaseOrders = [row({ lockVersion: 9 })];
    await nextTick();
    m.state.confirmPendingAction();
    assert.equal(m.requests.length, 1);
    assert.equal(
        m.requests[0].url,
        '/inventory/purchase-orders/order-a/approve',
    );
    assert.equal(m.requests[0].data.lock_version, 1);
    m.scope.stop();
});
test('actual child processing emits synchronously with stable identity and independent unmount tokens', () => {
    const childCode = transpile(
        compileScript(
            parse(
                readFileSync(
                    new URL(
                        'resources/js/components/inventory/VerifiedReceivingForm.vue',
                        root,
                    ),
                    'utf8',
                ),
            ).descriptor,
            { id: 'processing-child' },
        ).content,
    );
    const events = [];
    const cleanups = [];
    const scopes = [];
    const child = () => {
        const props = reactive({
            branchId: 1,
            nonce: 'synthetic-nonce',
            row: row(),
        });
        const exp = {};
        runInNewContext(childCode, {
            exports: exp,
            crypto: { randomUUID },
            require: (name) =>
                name === 'vue'
                    ? {
                          ...require('vue'),
                          onBeforeUnmount: (fn) => cleanups.push(fn),
                      }
                    : name === '@inertiajs/vue3'
                      ? {
                            useForm: (values) =>
                                reactive({
                                    ...values,
                                    processing: false,
                                    errors: {},
                                    reset() {},
                                    clearErrors() {},
                                }),
                        }
                      : name.startsWith('@/')
                        ? new Proxy({}, { get: () => () => ({}) })
                        : require(name),
        });
        const scope = effectScope();
        scopes.push(scope);
        const state = scope.run(() =>
            exp.default.setup(props, {
                expose() {},
                emit: (name, payload) => events.push({ name, ...payload }),
            }),
        );

        return { props, state };
    };
    const a = child(),
        b = child();
    a.state.form.processing = true;
    assert.equal(events.at(-1).processing, true);
    const oldToken = events.at(-1).token;
    b.state.form.processing = true;
    const newToken = events.at(-1).token;
    assert.notEqual(oldToken, newToken);
    a.props.branchId = 2;
    assert.equal(events.at(-1).branchId, 2);
    assert.equal(events.at(-1).token, oldToken);
    cleanups[0]();
    assert.equal(events.at(-1).processing, false);
    assert.equal(events.at(-1).token, oldToken);
    assert.equal(b.state.form.processing, true);
    scopes.forEach((scope) => scope.stop());
});
