import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/pages/Billing/PayLaterCollections.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'collection-search',
}).content;
const code = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
function harness(
    filters = { query: 'Applied invoice', age: 'over60' },
    overdueOnly = true,
) {
    const module = { exports: {} };
    const requests = [];
    const props = reactive({ collections: { filters, overdueOnly } });
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    router: {
                        get: (url, data) =>
                            requests.push({
                                url,
                                data: JSON.parse(JSON.stringify(data)),
                            }),
                    },
                };
            }

            if (name.startsWith('@/')) {
                return {};
            }

            return require(name);
        },
    });
    const api = module.exports.default.setup(props, { expose() {} });

    return { api, props, requests };
}

test('paging preserves applied search and age, not unfinished input', () => {
    const h = harness();
    h.api.filters.query = 'Unapplied draft';
    h.api.visit(2);
    assert.deepEqual(h.requests, [
        {
            url: '/pay-later-collections',
            data: {
                page: 2,
                overdue: 1,
                query: 'Applied invoice',
                age: 'over60',
            },
        },
    ]);
});

test('applying due-today-or-later clears contradictory overdue-only state', () => {
    const h = harness();
    h.api.filters.query = 'Synthetic target';
    h.api.filters.age = 'not_overdue';
    h.api.applyFilters();
    assert.deepEqual(h.requests[0].data, {
        page: 1,
        query: 'Synthetic target',
        age: 'not_overdue',
    });
});

test('enabling overdue-only resets a conflicting applied age while retaining search', () => {
    const h = harness(
        { query: 'Synthetic invoice', age: 'not_overdue' },
        false,
    );
    h.api.visit(1, true);
    assert.deepEqual(h.requests[0].data, {
        page: 1,
        overdue: 1,
        query: 'Synthetic invoice',
    });
});

test('server-normalized criteria replace draft controls after navigation', async () => {
    const h = harness();
    h.api.filters.query = ' draft ';
    h.props.collections.filters = { query: 'draft', age: 'all' };
    await nextTick();
    assert.equal(h.api.filters.query, 'draft');
    assert.equal(h.api.filters.age, 'all');
    assert.equal(h.requests.length, 0);
});
