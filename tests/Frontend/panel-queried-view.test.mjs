import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const source = readFileSync('resources/js/pages/PanelClaims/Index.vue', 'utf8');
const code = ts.transpileModule(
    compileScript(parse(source).descriptor, { id: 'queried-view' }).content,
    { compilerOptions: { module: ts.ModuleKind.CommonJS } },
).outputText;
function setup(tab = 'queried') {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { usePage: () => ({ props: {} }), router: {} };
            }

            if (name.startsWith('@/')) {
                return {};
            }

            return require(name);
        },
    });
    const filters = Object.fromEntries(
        [
            'from',
            'to',
            'panel',
            'claimNo',
            'amountMin',
            'amountMax',
            'invoiceNo',
            'name',
            'doctor',
            'visitType',
            'visitFrom',
            'visitTo',
            'dueFrom',
            'dueTo',
            'diagnosis',
            'treatment',
        ].map((key) => [key, '']),
    );

    return module.exports.default.setup(
        {
            work: {
                branch: { id: 1, today: '2026-11-26' },
                filters: {
                    ...filters,
                    tab,
                    range: 'all',
                    panel: 'Synthetic Panel',
                },
                tabs: { queried: 21, submitted: 22, rejected: 1 },
                eligible: { data: [] },
                claims: { data: [] },
                panels: [],
                can: {},
            },
        },
        { expose: () => {} },
    );
}

test('actual page script exposes distinct Queried count without narrowing existing tabs', () => {
    const page = setup();
    assert.equal(
        page.tabs.value.find((tab) => tab.value === 'queried').count,
        21,
    );
    assert.equal(
        page.tabs.value.find((tab) => tab.value === 'submitted').count,
        22,
    );
    assert.equal(
        page.tabs.value.find((tab) => tab.value === 'rejected').label,
        'Rejected',
    );
    assert.equal(page.invoiceMode.value, false);
});

test('actual query builder retains Queried and filters across pagination', () => {
    const page = setup();
    const query = JSON.parse(JSON.stringify(page.query({ claims_page: 2 })));
    assert.deepEqual(query, {
        tab: 'queried',
        panel: 'Synthetic Panel',
        claims_page: 2,
    });
    assert.equal(page.query({ tab: 'rejected' }).tab, 'rejected');
});

test('Queried explanation points to recorded claim history without invented query text', () => {
    assert.match(source, /v-else-if="work\.filters\.tab === 'queried'"/);
    assert.match(
        source,
        /Claims with a recorded Panel query\. Open a claim to review its\s+recorded history and update it\./,
    );
});

test('full actual template renders Queried with unchanged six-bucket summary DTO', async () => {
    const { createSSRApp, h } = await import('vue');
    const { renderToString } = await import('@vue/server-renderer');
    const container = {
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    };
    const compiled = ts.transpileModule(
        compileScript(parse(source).descriptor, {
            id: 'queried-render',
            inlineTemplate: true,
        }).content,
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const module = { exports: {} };
    runInNewContext(compiled, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    usePage: () => ({ props: {} }),
                    router: {},
                };
            }

            if (name === '@/types/billing') {
                return { myr: (sen) => `RM ${(sen / 100).toFixed(2)}` };
            }

            if (name.startsWith('@/')) {
                return new Proxy(container, {
                    get: (object, key) =>
                        key in object ? object[key] : container,
                });
            }

            return require(name);
        },
    });
    const empty = { data: [], total: 0, currentPage: 1, lastPage: 1 };
    const work = {
        branch: { id: 1, name: 'Synthetic branch', today: '2026-11-26' },
        filters: { tab: 'queried', range: 'all', panel: '' },
        tabs: { queried: 0 },
        panels: [],
        can: {},
        waiting: 0,
        eligible: empty,
        claims: empty,
        summary: Object.fromEntries(
            [
                'pending',
                'overdue',
                'approved',
                'received',
                'rejected',
                'outstanding',
            ].map((key) => [key, { count: 0, amountSen: 0 }]),
        ),
    };
    const html = await renderToString(
        createSSRApp({ render: () => h(module.exports.default, { work }) }),
    );
    assert.match(html, /Claims with a recorded Panel query/);
    const payout = html.match(/aria-label="Payouts"[\s\S]*?<\/section>/)?.[0];
    assert.ok(payout);
    assert.doesNotMatch(payout, /Queried/);

    for (const label of ['Approved', 'Received', 'Rejected', 'Outstanding']) {
        assert.ok(payout.includes(label));
    }
});
