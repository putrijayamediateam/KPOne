import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { effectScope, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/GovernedStock.vue',
        import.meta.url,
    ),
    'utf8',
);
const code = ts.transpileModule(
    compileScript(parse(source).descriptor, {
        id: 'actual-governed-dialog-test',
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const row = (extra = {}) => ({
    id: 91,
    public_id: 'case-a',
    sku_code: 'SYN-A',
    batch_number: 'BATCH-A',
    location: 'Store',
    blind: true,
    lock_version: 3,
    stock_unit: 'tablet',
    ...extra,
});
function setup() {
    const page = reactive({
        props: { branchContext: { active: { id: 1 } }, errors: {} },
    });
    const requests = [];
    const useForm = (defaults) => {
        let transform = (data) => data;
        const form = reactive({
            ...defaults,
            errors: {},
            processing: false,
            reset() {
                Object.assign(form, defaults);
            },
            clearErrors() {
                form.errors = {};
            },
            transform(fn) {
                transform = fn;

                return form;
            },
            post(url) {
                requests.push({ url, data: transform(form) });
            },
        });

        return form;
    };
    const exports = {};
    const inert = {
        useForm,
        usePage: () => page,
        router: { get() {}, post() {} },
        Head: {},
    };
    runInNewContext(code, {
        exports,
        crypto: { randomUUID: () => 'synthetic-retry-key' },
        require: (name) =>
            name === '@inertiajs/vue3'
                ? inert
                : name.startsWith('@/') || name === '@lucide/vue'
                  ? new Proxy({}, { get: () => ({}) })
                  : require(name),
    });
    const props = reactive({
        stock: {
            branchId: 1,
            branchName: 'Synthetic branch',
            mode: 'count',
            q: '',
            state: '',
            legacyLimit: 'Read only',
            canPropose: true,
            cases: { rows: [row()], total: 1, page: 1, lastPage: 1 },
            targets: { rows: [], total: 0, page: 1, lastPage: 1 },
        },
    });
    const scope = effectScope();
    const state = scope.run(() =>
        exports.default.setup(props, { expose() {} }),
    );

    return { page, props, state, requests, scope };
}
test('actual recount and approval dialogs retain original case/version after list projection refresh', async () => {
    for (const action of ['recounted', 'approved']) {
        const m = setup();
        m.state.showCase(row(), action);
        m.state.form.quantity = '2';
        m.props.stock.cases.rows = [
            row({
                id: 92,
                public_id: 'case-b',
                lock_version: 9,
                sku_code: 'SYN-B',
            }),
        ];
        await nextTick();
        m.state.submit();
        assert.equal(m.requests.length, 1);
        assert.equal(m.requests[0].url, `/inventory/governed/91/${action}`);
        assert.equal(m.requests[0].data.lock_version, 3);
        assert.equal(m.requests[0].data.expected_branch_id, 1);
        assert.equal(m.requests[0].data.retry_key, 'synthetic-retry-key');
        assert.match(m.state.label.value, /SYN-A/);

        if (action === 'recounted') {
            assert.equal(m.requests[0].data.quantity, '2');
        } else {
            assert.equal('quantity' in m.requests[0].data, false);
        }

        m.scope.stop();
    }
});
test('actual branch identity change closes the pending dialog and clears target/draft without submission', async () => {
    const m = setup();
    m.state.showCase(row(), 'recounted');
    m.state.form.quantity = '2';
    m.page.props.branchContext.active.id = 2;
    await nextTick();
    assert.equal(m.state.open.value, false);
    assert.equal(m.state.endpoint.value, '');
    assert.equal(m.state.label.value, '');
    assert.equal(m.state.form.quantity, '');
    assert.equal(m.requests.length, 0);
    m.scope.stop();
});
