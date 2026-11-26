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
    'resources/js/pages/Billing/CashClosingMonth.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'cash-month-filters',
}).content;
const code = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
function harness() {
    const module = { exports: {} };
    const requests = [];
    const shared = reactive({
        props: { branchContext: { active: { id: 1 } } },
    });
    const props = reactive({
        cashClosingMonth: {
            branch: 'Synthetic branch',
            month: '2026-11',
            today: '2026-11-27',
            summary: {
                countedSen: -150,
                expectedSen: -100,
                differenceSen: -50,
                countsShown: 2,
            },
            rows: [
                {
                    date: '2026-11-24',
                    status: 'approved',
                    flags: ['changed_after_approval'],
                    countedCashSen: -150,
                    expectedCashSen: -100,
                    differenceSen: -50,
                },
                {
                    date: '2026-11-25',
                    status: 'waiting',
                    flags: ['not_approved'],
                    countedCashSen: 0,
                    expectedCashSen: 0,
                    differenceSen: 0,
                },
                {
                    date: '2026-11-26',
                    status: 'not_counted',
                    flags: ['no_count', 'not_approved'],
                    countedCashSen: null,
                    expectedCashSen: null,
                    differenceSen: null,
                },
                {
                    date: '2026-11-27',
                    status: 'open',
                    flags: [],
                    countedCashSen: null,
                    expectedCashSen: null,
                    differenceSen: null,
                },
                {
                    date: '2026-11-28',
                    status: 'open',
                    flags: [],
                    countedCashSen: null,
                    expectedCashSen: null,
                    differenceSen: null,
                },
            ],
        },
    });
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    usePage: () => shared,
                    router: {
                        get: (url, data, options) =>
                            requests.push({ url, data, options }),
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

    return { api, props, requests, shared };
}
const dates = (h) => Array.from(h.api.filteredRows.value, (row) => row.date);

test('status and authoritative flags intersect without changing order or approved money snapshots', () => {
    const h = harness();
    const original = JSON.stringify(h.props.cashClosingMonth);
    h.api.flagFilter.value = 'not_approved';
    assert.deepEqual(dates(h), ['2026-11-25', '2026-11-26']);
    h.api.statusFilter.value = 'waiting';
    assert.deepEqual(dates(h), ['2026-11-25']);
    h.api.statusFilter.value = 'approved';
    h.api.flagFilter.value = 'changed_after_approval';
    assert.deepEqual(dates(h), ['2026-11-24']);
    assert.equal(h.api.filteredRows.value[0], h.props.cashClosingMonth.rows[0]);
    assert.equal(h.api.filteredRows.value[0].expectedCashSen, -100);
    assert.equal(JSON.stringify(h.props.cashClosingMonth), original);
    assert.equal(h.requests.length, 0);
});
test('Today means the supplied business date, not every open row or computer date', () => {
    const h = harness();
    h.api.statusFilter.value = 'today';
    assert.deepEqual(dates(h), ['2026-11-27']);
    h.api.flagFilter.value = 'no_count';
    assert.deepEqual(dates(h), []);
    h.api.clearFilters();
    assert.equal(dates(h).length, 5);
    assert.equal(h.api.filtersActive.value, false);
});
test('same loaded month refresh and failed navigation preserve criteria; changed month or branch resets them', async () => {
    const h = harness();
    h.api.statusFilter.value = 'approved';
    h.api.flagFilter.value = 'changed_after_approval';
    h.props.cashClosingMonth = { ...h.props.cashClosingMonth };
    await nextTick();
    assert.equal(h.api.statusFilter.value, 'approved');
    h.api.goTo('2026-10');
    h.requests[0].options.onStart();
    h.requests[0].options.onNetworkError();
    h.requests[0].options.onFinish();
    assert.equal(h.api.failed.value, true);
    assert.equal(h.props.cashClosingMonth.month, '2026-11');
    assert.equal(h.api.flagFilter.value, 'changed_after_approval');
    h.props.cashClosingMonth.month = '2026-10';
    await nextTick();
    assert.equal(h.api.filtersActive.value, false);
    h.api.statusFilter.value = 'returned';
    h.shared.props.branchContext.active.id = 2;
    await nextTick();
    assert.equal(h.api.filtersActive.value, false);
});
test('before-start and empty rows stay empty without inventing days or totals', () => {
    const h = harness();
    h.props.cashClosingMonth.rows = [];
    h.props.cashClosingMonth.beforeStart = true;
    const summary = JSON.stringify(h.props.cashClosingMonth.summary);
    h.api.flagFilter.value = 'no_count';
    assert.deepEqual(dates(h), []);
    h.api.clearFilters();
    assert.deepEqual(dates(h), []);
    assert.equal(JSON.stringify(h.props.cashClosingMonth.summary), summary);
});
