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
test('actual PO create sends date/reference and preserves refused draft before success resets them', () => {
    const m = setup();
    Object.assign(m.state.purchaseOrder, {
        supplier: 'supplier-a',
        destination: 'location-a',
        expectedDeliveryDate: '2026-12-01',
        supplierReference: 'SYN-QT',
        lines: [
            {
                key: 'a',
                sku: 'sku-a',
                quantity: '2',
                estimatedUnitCostRm: '1.25',
            },
        ],
    });
    m.state.createPurchaseOrder();
    const r = m.requests.at(-1);
    assert.equal(r.data.expected_delivery_date, '2026-12-01');
    assert.equal(r.data.supplier_reference, 'SYN-QT');
    assert.equal(r.data.lines[0].unit_cost_sen, 125);
    r.options.onError({ supplier_reference: 'Refused' });
    assert.equal(m.state.purchaseOrder.supplierReference, 'SYN-QT');
    assert.equal(m.state.operationError.value, 'Refused');
    r.options.onSuccess();
    assert.equal(m.state.purchaseOrder.supplierReference, '');
    assert.equal(m.state.purchaseOrder.expectedDeliveryDate, '');
    m.scope.stop();
});
test('actual edit preserves omitted legacy DTO fields but explicitly cleared fields are sent', () => {
    const m = setup(
        row({ expectedDeliveryDate: undefined, supplierReference: undefined }),
    );
    m.state.updatePurchaseOrder(m.props.operations.purchaseOrders[0]);
    let r = m.requests.at(-1);
    assert.equal('expected_delivery_date' in r.data, false);
    assert.equal('supplier_reference' in r.data, false);
    Object.assign(m.state.purchaseOrderEdits['order-a'], {
        expectedDeliveryDate: '',
        supplierReference: '',
    });
    m.state.updatePurchaseOrder(m.props.operations.purchaseOrders[0]);
    r = m.requests.at(-1);
    assert.equal(r.data.expected_delivery_date, '');
    assert.equal(r.data.supplier_reference, '');
    assert.equal(r.data.lock_version, 1);
    m.scope.stop();
});
test('same identity refresh retains refused metadata; changed version or branch resets current context without posting', async () => {
    const m = setup();
    const e = m.state.purchaseOrderEdits['order-a'];
    e.supplierReference = 'UNSAVED';
    m.state.operationError.value = 'Retry needed';
    m.props.operations.purchaseOrders = [row()];
    await nextTick();
    assert.equal(e.supplierReference, 'UNSAVED');
    assert.equal(m.state.operationError.value, 'Retry needed');
    m.props.operations.purchaseOrders = [
        row({ lockVersion: 2, supplierReference: 'SERVER' }),
    ];
    await nextTick();
    assert.equal(e.supplierReference, 'SERVER');
    e.supplierReference = 'OTHER-DRAFT';
    m.state.purchaseOrder.supplierReference = 'NEW-DRAFT';
    m.props.operations.expectedBranchId = 2;
    await nextTick();
    assert.equal(e.supplierReference, 'SERVER');
    assert.equal(m.state.purchaseOrder.supplierReference, '');
    assert.equal(m.requests.length, 0);
    m.scope.stop();
});
