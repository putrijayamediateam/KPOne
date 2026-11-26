import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h, reactive } from 'vue';
const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const stub = {
    render() {
        return h('div', this.$slots.default?.());
    },
};
const ui = new Proxy({ default: stub }, { get: (v, k) => v[k] ?? stub });
const evaluate = (source) => {
    const exports = {};
    runInNewContext(
        ts.transpileModule(source, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports, require: resolve },
    );

    return exports;
};
const load = (path) =>
    evaluate(
        compileScript(parse(read(path)).descriptor, {
            id: path,
            inlineTemplate: true,
        }).content,
    );
function resolve(name) {
    if (name === '@inertiajs/vue3') {
        return {
            Head: stub,
            router: {},
            usePage: () => ({
                props: { branchContext: { active: { id: 1 } }, errors: {} },
            }),
            useForm: (v) =>
                reactive({
                    ...v,
                    processing: false,
                    errors: {},
                    clearErrors() {},
                    reset() {},
                }),
        };
    }

    if (name === '@/components/inventory/StockCountCaseList.vue') {
        return load('resources/js/components/inventory/StockCountCaseList.vue');
    }

    if (name.startsWith('@/lib/')) {
        return evaluate(read(`resources/js/lib/${name.slice(6)}.ts`));
    }

    if (name.startsWith('@/') || name === '@lucide/vue') {
        return ui;
    }

    return require(name);
}
const page = load('resources/js/pages/Inventory/GovernedStock.vue').default;
const row = (extra = {}) => ({
    id: 91,
    public_id: 'writeoff-a',
    balance_id: 3,
    expected_quantity: '12.000',
    expected_balance_version: 2,
    current_balance_version: 2,
    proposer_user_id: 5,
    kind: 'writeoff',
    reason: 'damaged',
    quantity: '2.000',
    disposal_reference: 'SYN-LOG',
    blind: false,
    state: 'awaiting_approval',
    lock_version: 1,
    sku_code: 'SYN-WRITEOFF',
    stock_unit: 'tablet',
    batch_number: 'B-ONE',
    location: 'Synthetic store',
    history: [],
    canCount: false,
    canApprove: false,
    canReverse: false,
    ...extra,
});
const render = async (rows, mode = 'writeoff') =>
    renderToString(
        createSSRApp({
            render: () =>
                h(page, {
                    stock: {
                        branchId: 1,
                        branchName: 'Synthetic branch',
                        mode,
                        q: '',
                        state: '',
                        legacyLimit: 'Read only',
                        canPropose: false,
                        cases: {
                            rows,
                            total: rows.length,
                            page: 1,
                            lastPage: 1,
                        },
                        targets: { rows: [], total: 0, page: 1, lastPage: 1 },
                    },
                }),
        }),
    );
test('actual writeoff page uses named disclosures with exact requested unit/reason and no new commands', async () => {
    const html = await render([row()]);
    assert.match(html, /aria-label="Stock write-off cases"/);
    assert.match(html, /data-case-trigger="writeoff-a"/);
    assert.match(html, /Requested quantity:[\s\S]*?2[\s\S]*?tablet/);
    assert.match(html, /Damaged/);
    assert.match(html, /SYN-LOG/);
    assert.doesNotMatch(
        html,
        /Returned|Return for correction|4 lines|Approve and post/,
    );
});
test('actual writeoff summary keeps zero distinct from null and does not disclose unauthorized actions', async () => {
    const zero = await render([row({ quantity: '0.000' })]);
    assert.match(zero, /Requested quantity:[\s\S]*?0[\s\S]*?tablet/);
    const missing = await render([row({ quantity: null })]);
    assert.match(missing, /Requested quantity:[\s\S]*?Unavailable/);
    assert.doesNotMatch(missing, /Approve and post|Propose reversal/);
});
test('actual count page retains default label and blind omission without writeoff summary', async () => {
    const html = await render(
        [
            row({
                kind: 'count',
                blind: true,
                expected_quantity: undefined,
                quantity: '98765.432',
                state: 'awaiting_count',
            }),
        ],
        'count',
    );
    assert.match(html, /aria-label="Stock count cases"/);
    const trigger = html.match(
        /<button[^>]*data-case-trigger="writeoff-a"[\s\S]*?<\/button>/,
    )?.[0];
    assert.ok(trigger);
    assert.match(trigger, /Blind count/);
    assert.doesNotMatch(trigger, /98765|Requested quantity|Unavailable/);
});

test('governed reversal reason is contextual correction without implying a supplier receipt', async () => {
    const html = await render([
        row({
            kind: 'reversal',
            reason: 'correction',
            quantity: '1.000',
            state: 'posted',
        }),
    ]);
    assert.match(html, /Correction/);
    assert.doesNotMatch(html, /Receipt correction/);
});

test('empty writeoff result keeps one named list and one truthful empty message', async () => {
    const html = await render([]);
    assert.match(html, /aria-label="Stock write-off cases"/);
    assert.equal((html.match(/No matching cases\./g) ?? []).length, 1);
    assert.doesNotMatch(html, /data-case-trigger=/);
});
