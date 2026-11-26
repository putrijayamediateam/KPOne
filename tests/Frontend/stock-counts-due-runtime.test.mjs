import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    new URL(
        '../../resources/js/components/inventory/StockCountsDueList.vue',
        import.meta.url,
    ),
    'utf8',
);
const compiled = compileScript(parse(source).descriptor, {
    id: 'counts-due-test',
    inlineTemplate: true,
}).content;
const exports = {};
runInNewContext(
    ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports, require },
);
const component = exports.default;
const row = (extra = {}) => ({
    skuPublicId: 'sku-one',
    skuCode: 'SYN-COUNTS',
    item: 'Synthetic item',
    locationPublicId: 'store',
    location: 'Store',
    class: 'A',
    lastCounted: null,
    dueOn: null,
    overdueDays: null,
    open: false,
    ...extra,
});
const render = (rows, slots = {}) =>
    renderToString(
        createSSRApp({ render: () => h(component, { rows }, slots) }),
    );

test('actual counts list keeps same SKU at different locations as distinct rows', async () => {
    const html = await render([
        row(),
        row({
            locationPublicId: 'dispensary',
            location: 'Dispensary',
            lastCounted: '25 Nov 2026',
        }),
    ]);
    assert.match(html, /data-count-row="sku-one:store"/);
    assert.match(html, /data-count-row="sku-one:dispensary"/);
    assert.equal((html.match(/SYN-COUNTS/g) ?? []).length, 2);
    assert.match(html, /25 Nov 2026/);
    assert.match(html, /No completed count/);
});

test('blind list never renders surplus system quantity or invents a date', async () => {
    const html = await render([
        row({ quantity: '98765.432', expected_quantity: '98765.432' }),
    ]);
    assert.doesNotMatch(html, /98765|Due today|1970|On hand/);
    assert.match(html, /No completed count/);
});

test('status and action slots receive exact row authority context without list inventing actions', async () => {
    const rows = [
        row(),
        row({
            locationPublicId: 'room',
            open: true,
            class: 'C',
            overdueDays: 31,
        }),
    ];
    const seen = [];
    const html = await render(rows, {
        status: ({ row: current }) =>
            h(
                'span',
                `Class ${current.class} ${current.overdueDays ?? 'Never counted'}`,
            ),
        action: ({ row: current }) => {
            seen.push(current);

            return current.open
                ? h('p', 'Counts open below')
                : h(
                      'button',
                      { 'aria-label': `Start ${current.locationPublicId}` },
                      'Start count',
                  );
        },
    });
    assert.deepEqual(seen, rows);
    assert.match(html, /Class A Never counted/);
    assert.match(html, /Class C 31/);
    assert.equal((html.match(/<button/g) ?? []).length, 1);
    assert.match(html, /Counts open below/);
    assert.doesNotMatch(await render(rows), /<button|Start count/);
});

test('empty rows render no fabricated data and item text is escaped', async () => {
    assert.doesNotMatch(await render([]), /data-count-row=/);
    const html = await render([
        row({ item: '<script>unexpected</script>', skuCode: 'A&B' }),
    ]);
    assert.match(html, /&lt;script&gt;unexpected&lt;\/script&gt;/);
    assert.match(html, /A&amp;B/);
    assert.doesNotMatch(html, /<script>/);
});
