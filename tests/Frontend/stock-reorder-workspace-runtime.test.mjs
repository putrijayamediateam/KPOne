import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h, nextTick } from 'vue';
const require = createRequire(import.meta.url);
const read = (file) =>
    readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/Inventory/ReorderSuggestions.vue');
const evaluate = (text, resolve = require) => {
    const out = { exports: {} };
    runInNewContext(
        ts.transpileModule(text, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: out.exports, require: resolve },
    );

    return out.exports;
};
const actualTerms = evaluate(read('resources/js/lib/supplier-terms.ts'));
const row = (extra = {}) => ({
    key: 'sku:store',
    skuPublicId: 'sku-one',
    locationPublicId: 'store-one',
    skuCode: 'SYN-SKU',
    itemName: 'Synthetic medicine',
    location: 'Synthetic store',
    stockUnit: 'capsule',
    reorderLevel: '10.000',
    maxLevel: null,
    onHand: '0.000',
    onOrder: '2.000',
    incoming: '1.000',
    averageDailyUse: '0.250',
    sold7: null,
    sold14: null,
    sold30: null,
    supplier: {
        name: 'Synthetic supplier',
        leadTimeDays: 3,
        packDescription: 'Box',
        packQuantity: '5.000',
        minimumOrderPacks: 1,
    },
    suggestedPacks: 2,
    suggestedQuantity: '10.000',
    coveredByOrders: false,
    estimateSen: 0,
    ...extra,
});
const props = (extra = {}) => ({
    suggestions: {
        branchName: 'Synthetic branch',
        canSeeCost: false,
        canRequest: true,
        lookBackDays: 30,
        filters: { q: '', need: 'order' },
        rows: [row()],
        page: 1,
        lastPage: 1,
        total: 1,
        ...extra,
    },
});
function harness(inlineTemplate = true) {
    const calls = [];
    const money = [];
    const stub = {
        setup:
            (_, { slots, attrs }) =>
            () =>
                h('div', attrs, slots.default?.()),
    };
    const component = evaluate(
        compileScript(parse(source).descriptor, {
            id: 'reorder-runtime',
            inlineTemplate,
        }).content,
        (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: stub,
                    router: { get: (...args) => calls.push(args) },
                };
            }

            if (name === '@lucide/vue') {
                return { ArrowLeft: stub };
            }

            if (name === '@/lib/supplier-terms') {
                return {
                    ...actualTerms,
                    formatRinggit: (sen) => {
                        money.push(sen);

                        return actualTerms.formatRinggit(sen);
                    },
                };
            }

            if (name.startsWith('@/components/')) {
                return {
                    ActionLink: stub,
                    Button: stub,
                    Input: stub,
                    PageHeader: stub,
                    CompactPagination: stub,
                };
            }

            return require(name);
        },
    ).default;

    return { component, calls, money };
}
test('actual page shows exact four summary fields and never formats hidden raw cost', async () => {
    const v = harness();
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    v.component,
                    props({ rows: [row({ estimateSen: 987654321 })] }),
                ),
        }),
    );
    assert.match(html, /ITEM \/ LOCATION/);
    assert.match(html, /ON HAND/);
    assert.match(html, /REORDER AT/);
    assert.match(html, /SUGGESTED/);
    assert.match(html, /0 capsule/);
    assert.match(html, /10 capsule/);
    assert.match(html, /Synthetic store/);
    assert.equal(v.money.length, 0);
    assert.doesNotMatch(
        html,
        /987654321|9876543\.21|Estimated cost \(supplier price list\)/,
    );
});
test('authorized zero and missing estimate remain distinct; missing sold is not fabricated sales', async () => {
    const v = harness();
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    v.component,
                    props({
                        canSeeCost: true,
                        rows: [
                            row(),
                            row({ key: 'other', estimateSen: undefined }),
                        ],
                    }),
                ),
        }),
    );
    assert.match(html, /RM 0\.00/);
    assert.match(html, /No price recorded/);
    assert.match(html, /None in the last 30 days/);
    assert.deepEqual(v.money, [0]);
});
test('covered by orders and zero without orders remain different existing server states', async () => {
    const v = harness();
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    v.component,
                    props({
                        rows: [
                            row({
                                coveredByOrders: true,
                                suggestedQuantity: '0.000',
                            }),
                            row({ key: 'zero', suggestedQuantity: '0.000' }),
                        ],
                    }),
                ),
        }),
    );
    assert.match(html, /Covered by orders/);
    assert.match(html, /At reorder level, no order needed/);
    assert.doesNotMatch(html, /Ask for SYN-SKU/);
});
test('actual request handler refuses mixed locations and only prefills exact one-location quantities', async () => {
    const v = harness(false);
    const input = props({
        rows: [
            row(),
            row({
                key: 'second',
                skuPublicId: 'sku-two',
                locationPublicId: 'store-two',
                suggestedQuantity: '2.500',
            }),
        ],
    });
    const state = v.component.setup(input, { expose() {} });
    state.ticked.value = ['sku:store', 'second'];
    await nextTick();
    state.requestThese();
    assert.equal(v.calls.length, 0);
    state.ticked.value = ['second'];
    await nextTick();
    state.requestThese();
    assert.equal(v.calls.length, 1);
    assert.equal(v.calls[0][0], '/inventory/purchase-requests');
    assert.deepEqual(JSON.parse(JSON.stringify(v.calls[0][1])), {
        location: 'store-two',
        lines: ['sku-two:2.5'],
    });
    state.ticked.value = [];
    await nextTick();
    state.requestThese();
    assert.equal(v.calls.length, 1);
});
test('optional help retains native disclosure while mandatory scope and cost notice stay outside it', () => {
    const start = source.indexOf('<aside');
    const end = source.indexOf('</aside>', start);
    assert.ok(start > 0 && end > start);
    const outside = (source.slice(0, start) + source.slice(end + 8)).replace(
        /\s+/g,
        ' ',
    );
    assert.match(outside, /A suggestion never places an order/);
    assert.match(
        outside,
        /Estimated costs are shown only to a Director or Finance Officer/,
    );
    assert.match(outside, /A request is for one location/);
    assert.match(source, /v-show="helpVisible"/);
    assert.match(source, /@click="ticked = \[\]"/);
    assert.doesNotMatch(source, /router\.(post|put|patch|delete)/);
});

test('applied no-match search does not claim the whole branch needs no stock', async () => {
    const v = harness();
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    v.component,
                    props({
                        rows: [],
                        total: 0,
                        filters: { q: 'NO-MATCH', need: 'order' },
                    }),
                ),
        }),
    );
    assert.match(html, /No reorder suggestions match this search\./);
    assert.doesNotMatch(html, /Nothing needs ordering at this branch\./);
});

test('unfiltered empty ordering and all-low-stock projections retain distinct original messages', async () => {
    for (const [need, message] of [
        ['order', 'Nothing needs ordering at this branch.'],
        ['all', 'No stock is at or below its reorder level at this branch.'],
    ]) {
        const v = harness();
        const html = await renderToString(
            createSSRApp({
                render: () =>
                    h(
                        v.component,
                        props({ rows: [], total: 0, filters: { q: '', need } }),
                    ),
            }),
        );
        assert.ok(html.includes(message));
        assert.doesNotMatch(html, /No reorder suggestions match this search\./);
    }
});

test('supplier minimum uses the supplied packs and keeps null unspecified', async () => {
    const v = harness();
    const withMinimum = row();
    const missing = row({
        key: 'missing-minimum',
        supplier: { ...withMinimum.supplier, minimumOrderPacks: null },
    });
    const plural = row({
        key: 'plural-minimum',
        supplier: { ...withMinimum.supplier, minimumOrderPacks: 2 },
    });
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(v.component, props({ rows: [withMinimum, missing, plural] })),
        }),
    );
    const normalized = html.replace(/>\s+/g, '>').replace(/\s+</g, '<');
    assert.match(normalized, /Minimum order<\/dt>\s*<dd>1 pack<\/dd>/);
    assert.match(normalized, /Minimum order<\/dt>\s*<dd>2 packs<\/dd>/);
    assert.match(normalized, /Minimum order<\/dt>\s*<dd>Not specified<\/dd>/);
});
