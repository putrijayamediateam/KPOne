import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/SupplierReturns.vue',
        import.meta.url,
    ),
    'utf8',
);
function harness() {
    const start = source.indexOf('function search(');
    const end = source.indexOf('// STK-17: several lots', start);
    assert.ok(start >= 0 && end > start);
    const props = { returns: { show: 'open', lots: { page: 3 } } };
    const form = { processing: false };
    const groupForm = { processing: false };
    const calls = [];
    const output = { exports: {} };
    runInNewContext(
        ts.transpileModule(
            source.slice(start, end) + '\nexport {search,changeShow};',
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        {
            exports: output.exports,
            props,
            form,
            groupForm,
            q: { value: 'SYN-SKU' },
            selectedDelivery: { value: 17 },
            router: { get: (...args) => calls.push(args) },
        },
    );

    return { ...output.exports, props, form, groupForm, calls };
}
test('changing Show resets both request pages and retains lots delivery and existing search', () => {
    const h = harness();
    const target = { value: 'done' };
    h.changeShow({ target });
    assert.equal(
        target.value,
        'open',
        'Loaded filter stays displayed until response.',
    );
    assert.equal(h.calls[0][0], '/inventory/supplier-returns');
    assert.deepEqual(JSON.parse(JSON.stringify(h.calls[0][1])), {
        q: 'SYN-SKU',
        show: 'done',
        page: 1,
        lots_page: 3,
        groups_page: 1,
        delivery: 17,
    });
    assert.equal(h.calls[0][2].preserveScroll, true);
});
test('pagination and search retain the server applied Show filter', () => {
    const h = harness();
    h.search(2, 4, 3, 22);
    assert.deepEqual(JSON.parse(JSON.stringify(h.calls[0][1])), {
        q: 'SYN-SKU',
        show: 'open',
        page: 2,
        lots_page: 4,
        groups_page: 3,
        delivery: 22,
    });
    h.props.returns.show = 'all';
    h.search();
    assert.equal(h.calls[1][1].show, 'all');
});
test('either pending mutation refuses a Show navigation without rewriting loaded state', () => {
    for (const owner of ['form', 'groupForm']) {
        const h = harness();
        h[owner].processing = true;
        const target = { value: 'done' };
        h.changeShow({ target });
        assert.equal(h.calls.length, 0);
        assert.equal(target.value, 'open');
    }

    assert.match(source, /:value="returns\.show"/);
    assert.match(
        source,
        /:disabled="form\.processing \|\| groupForm\.processing"/,
    );
    assert.match(source.replace(/\s+/g, ' '), /Search below applies only to individual returns\./);
    assert.match(source.replace(/\s+/g, ' '), /does not mean a refund or payment was made\./);
});
