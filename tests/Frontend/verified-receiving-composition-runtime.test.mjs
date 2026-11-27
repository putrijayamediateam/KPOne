import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, effectScope, h, nextTick, reactive } from 'vue';
const require = createRequire(import.meta.url);
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const source = read(
    'resources/js/components/inventory/VerifiedReceivingForm.vue',
);
const stub = {
    render() {
        return h('input');
    },
};
const ui = new Proxy({ default: stub }, { get: (v, k) => v[k] ?? stub });
const row = (extra = {}) => ({
    publicId: 'synthetic-order',
    number: 'PO-SYN-01',
    status: 'approved',
    lockVersion: 7,
    supplier: 'Synthetic supplier',
    destination: 'Synthetic store',
    lines: [
        {
            publicId: 'line-a',
            sku: 'SYN-A',
            item: 'Synthetic item',
            stockUnit: 'tablet',
            orderedQuantity: '12.000',
            receivedQuantity: '0.000',
            outstandingQuantity: '12.000',
        },
        { publicId: 'line-b', sku: 'SYN-B', stockUnit: 'unit' },
    ],
    ...extra,
});
function harness(
    inlineTemplate = false,
    props = reactive({ row: row(), branchId: 1, nonce: 'synthetic-nonce' }),
) {
    const calls = [];
    let sequence = 0;
    const evaluate = (text) => {
        const exports = {};
        runInNewContext(
            ts.transpileModule(text, {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            }).outputText,
            {
                exports,
                crypto: { randomUUID: () => `synthetic-retry-${++sequence}` },
                require: resolve,
            },
        );

        return exports;
    };
    const resolve = (name) => {
        if (name === '@inertiajs/vue3') {
            return {
                useForm: (defaults) => {
                    let transform = (data) => data;
                    const initial = structuredClone(defaults);
                    const form = reactive({
                        ...defaults,
                        errors: {},
                        processing: false,
                        reset() {
                            Object.assign(form, structuredClone(initial));
                        },
                        clearErrors() {
                            form.errors = {};
                        },
                        transform(fn) {
                            transform = fn;

                            return form;
                        },
                        post(url) {
                            calls.push({
                                url,
                                payload: JSON.parse(
                                    JSON.stringify(transform(form)),
                                ),
                            });
                        },
                    });

                    return form;
                },
            };
        }

        if (name.startsWith('@/lib/')) {
            return evaluate(read(`resources/js/lib/${name.slice(6)}.ts`));
        }

        if (name.startsWith('@/')) {
            return ui;
        }

        return require(name);
    };
    const component = evaluate(
        compileScript(parse(source).descriptor, {
            id: 'actual-verified-receiving',
            inlineTemplate,
        }).content,
    ).default;
    const scope = effectScope();
    const state = inlineTemplate
        ? null
        : scope.run(() => component.setup(props, { expose() {}, emit() {} }));

    return { calls, props, state, scope, component };
}
test('real receiving form displays supplied order/line context without fake delivery metadata or sums', async () => {
    const m = harness(true);
    const html = await renderToString(
        createSSRApp({ render: () => h(m.component, m.props) }),
    );

    for (const value of [
        'PO-SYN-01',
        'Synthetic supplier',
        'Synthetic store',
        '12.000',
        '0.000',
        'Previously received',
        'Outstanding on order',
        'Each lot has one outcome',
        'Request exception approval',
    ]) {
        assert.ok(html.includes(value), value);
    }

    assert.match(html, /—/);
    assert.doesNotMatch(
        html,
        /Received by|Received at|Arrived 09:40|adds up|Total paid for this delivery/,
    );
    m.scope.stop();
});
test('real submit preserves split outcome payload, exact costs, rejected-null cost and omitted lines', async () => {
    const m = harness();
    const s = m.state;
    s.form.delivery_reference = 'SYN-DO';
    s.form.lines[1].included = false;
    s.form.lines[0].physical_quantity = '3';
    s.form.lines[0].short_delivery = true;
    s.form.lines[0].lots = [
        {
            batch_number: 'SYN-1',
            expiry_date: '2028-01-01',
            quantity: '1',
            outcome: 'accepted',
            reason: null,
            cost_total_rm: '12.34',
            unit: null,
        },
        {
            batch_number: 'SYN-2',
            expiry_date: '2028-01-01',
            quantity: '1',
            outcome: 'quarantined',
            reason: 'damaged',
            cost_total_rm: '0.00',
            unit: null,
        },
        {
            batch_number: 'SYN-3',
            expiry_date: '2028-01-01',
            quantity: '1',
            outcome: 'rejected',
            reason: 'damaged',
            cost_total_rm: 'invalid',
            unit: null,
        },
    ];
    await nextTick();
    s.submit(true);
    assert.equal(m.calls.length, 1);
    assert.equal(
        m.calls[0].url,
        '/inventory/purchase-orders/synthetic-order/receiving-reviews',
    );
    const p = m.calls[0].payload;
    assert.equal(p.expected_branch_id, 1);
    assert.equal(p.lock_version, 7);
    assert.equal(p.receipt_session_nonce, 'synthetic-nonce');
    assert.equal(p.lines.length, 1);
    assert.equal(p.lines[0].short_delivery, true);
    assert.equal(p.lines[0].physical_quantity, '3');
    assert.deepEqual(
        p.lines[0].lots.map((l) => [l.outcome, l.cost_total_sen, l.unit]),
        [
            ['accepted', 1234, 'tablet'],
            ['quarantined', 0, 'tablet'],
            ['rejected', null, 'tablet'],
        ],
    );
    assert.equal('included' in p.lines[0], false);
    assert.equal('cost_total_rm' in p.lines[0].lots[0], false);
    m.scope.stop();
});
test('invalid entered cost blocks posting without clearing draft; server error remains in actual form', async () => {
    const m = harness();
    m.state.form.delivery_reference = 'KEEP-DRAFT';
    m.state.form.lines[0].lots[0].cost_total_rm = '1.234';
    m.state.submit(false);
    assert.equal(m.calls.length, 0);
    assert.match(m.state.costError.value, /up to 2 decimal/);
    assert.equal(m.state.form.delivery_reference, 'KEEP-DRAFT');
    assert.equal(m.state.form.lines[0].lots[0].cost_total_rm, '1.234');
    m.scope.stop();
});
test('unchanged retry is reused, edited draft rotates keys, and branch/version reset retains existing semantics', async () => {
    const m = harness();
    m.state.form.delivery_reference = 'SYN';
    await nextTick();
    m.state.submit(false);
    m.state.submit(false);
    assert.equal(m.calls[0].payload.retry_key, m.calls[1].payload.retry_key);
    m.state.form.delivery_reference = 'SYN-EDIT';
    await nextTick();
    m.state.submit(false);
    assert.notEqual(m.calls[2].payload.retry_key, m.calls[0].payload.retry_key);
    m.props.branchId = 2;
    await nextTick();
    assert.equal(m.state.form.delivery_reference, '');
    assert.equal(m.state.form.lines[0].lots.length, 1);
    m.scope.stop();
});

test('same-version server error props preserve typed receipt draft while a new version resets it', async () => {
    const m = harness();
    m.state.form.delivery_reference = 'KEEP-ERROR-DRAFT';
    m.state.form.lines[0].lots[0].batch_number = 'SYN-KEEP';
    m.state.form.errors = { lines: 'Synthetic refused outcome' };
    await nextTick();
    const retry = m.state.form.retry_key;
    const idempotency = m.state.form.idempotency_key;
    m.props.row = { ...m.props.row };
    await nextTick();
    assert.equal(m.state.form.delivery_reference, 'KEEP-ERROR-DRAFT');
    assert.equal(m.state.form.lines[0].lots[0].batch_number, 'SYN-KEEP');
    assert.equal(m.state.form.errors.lines, 'Synthetic refused outcome');
    assert.equal(m.state.form.retry_key, retry);
    assert.equal(m.state.form.idempotency_key, idempotency);
    assert.equal(m.calls.length, 0);
    m.props.row = { ...m.props.row, lockVersion: 8 };
    await nextTick();
    assert.equal(m.state.form.delivery_reference, '');
    assert.equal(m.state.form.lines[0].lots[0].batch_number, '');
    m.scope.stop();
});

test('real branch or order identity change resets to current line and nonce without submitting', async () => {
    for (const change of ['branch', 'order']) {
        const m = harness();
        m.state.form.delivery_reference = 'OLD-DRAFT';
        m.state.form.errors = { lines: 'Old refusal' };
        await nextTick();
        const retry = m.state.form.retry_key;
        m.props.nonce = 'new-nonce';

        if (change === 'branch') {
            m.props.branchId = 2;
        } else {
            m.props.row = row({
                publicId: 'new-order',
                lockVersion: 11,
                lines: [
                    { publicId: 'new-line', sku: 'SYN-NEW', stockUnit: 'vial' },
                ],
            });
        }

        await nextTick();
        await nextTick();
        assert.equal(m.state.form.delivery_reference, '');
        assert.equal(Object.keys(m.state.form.errors).length, 0);
        assert.equal(m.state.form.expected_branch_id, m.props.branchId);
        assert.equal(m.state.form.lock_version, m.props.row.lockVersion);
        assert.equal(m.state.form.receipt_session_nonce, 'new-nonce');
        assert.equal(
            m.state.form.lines[0].line_public_id,
            m.props.row.lines[0].publicId,
        );
        assert.equal(m.state.form.lines[0].lots[0].batch_number, '');
        assert.notEqual(m.state.form.retry_key, retry);
        assert.equal(m.calls.length, 0);
        m.scope.stop();
    }
});
