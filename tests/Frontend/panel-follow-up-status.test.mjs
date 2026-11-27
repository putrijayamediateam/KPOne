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
const compile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const money = { exports: {} };
runInNewContext(
    compile(readFileSync('resources/js/types/billing.ts', 'utf8')),
    { exports: money.exports },
);
const code = compile(
    compileScript(
        parse(
            readFileSync('resources/js/pages/PanelClaims/FollowUp.vue', 'utf8'),
        ).descriptor,
        { id: 'follow-up-context', inlineTemplate: true },
    ).content,
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const claim = {
    publicId: 'synthetic-claim',
    claimNo: 'SYN-LONG-CLAIM-123',
    supplementaryTo: 'PCL-SYN-PARENT',
    patientName: 'Synthetic patient with a long display name',
    invoiceNumber: 'INV-SYN-123',
    panelName: 'Synthetic Panel with a long agreement name',
    status: 'Submitted',
    amountLabel: 'Claimed, awaiting decision',
    amountSen: 15000,
    lastAction: 'Submitted to Panel',
    lastActionDate: '1 Oct 2026',
    lastActionBy: 'Staff member',
    daysSinceAction: 56,
    followUpDays: 30,
    showUrl: '/panel-claims/synthetic-claim',
};
async function render(overrides = {}) {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    Link: {
                        props: ['href'],
                        setup:
                            (p, { slots }) =>
                            () =>
                                h('a', { href: p.href }, slots.default?.()),
                    },
                    router: { get: () => assert.fail('SSR cannot navigate') },
                };
            }

            if (name === '@/components/panel/PanelWorkspaceNav.vue') {
                return { default: container };
            }

            if (name === '@/types/billing') {
                return money.exports;
            }

            if (name === '@/components/ui/button') {
                return { Button: container };
            }

            if (name === '@/components/ui/page-header') {
                return {
                    PageHeader: {
                        props: ['title', 'description'],
                        setup: (p) => () =>
                            h('header', [
                                h('h1', p.title),
                                h('p', p.description),
                            ]),
                    },
                };
            }

            if (name === '@/components/ui/pagination') {
                return {
                    CompactPagination: {
                        props: ['currentPage', 'lastPage', 'total'],
                        setup: (p) => () =>
                            h(
                                'nav',
                                { 'aria-label': 'Pagination' },
                                `${p.currentPage}/${p.lastPage}: ${p.total}`,
                            ),
                    },
                };
            }

            return require(name);
        },
    });

    return renderToString(
        createSSRApp({
            render: () =>
                h(module.exports.default, {
                    branch: { id: 1, name: 'Synthetic branch' },
                    filters: { panel: '' },
                    panels: [
                        {
                            code: 'SYN',
                            name: 'Synthetic Panel',
                            followUpDays: 30,
                        },
                    ],
                    claims: {
                        data: [
                            claim,
                            {
                                ...claim,
                                publicId: 'synthetic-unpaid',
                                status: 'Accepted, unpaid',
                                amountLabel: 'Unpaid',
                                amountSen: 0,
                            },
                        ],
                        total: 21,
                        currentPage: 1,
                        lastPage: 2,
                    },
                    ...overrides,
                }),
        }),
    );
}

test('actual status controls and pre-status counts retain distinct authoritative money labels', async () => {
    const html = await render({
        filters: { panel: 'SYN', status: 'submitted' },
        statusCounts: {
            prepared: 2,
            submitted: 21,
            queried: 3,
            accepted: 1,
            partially_accepted: 0,
            partially_paid: 4,
        },
    });
    assert.match(html, /aria-label="Status"/);
    assert.match(html, /Prepared after earlier submission/);
    assert.match(html, /Eligible claims by status/);
    assert.match(html, /before the status filter/);
    assert.match(html, /Clear status/);
    assert.match(html, /Claimed, awaiting decision RM 150.00/);
    assert.match(html, /Unpaid RM 0.00/);
    assert.doesNotMatch(html, /Total owed|Total unpaid|Total claimed/);
});

test('filtered empty status keeps eligible count context and a Panel-preserving reset', async () => {
    const html = await render({
        filters: { panel: 'SYN', status: 'queried' },
        statusCounts: { submitted: 21, queried: 0 },
        claims: { data: [], total: 0, currentPage: 1, lastPage: 1 },
    });
    assert.match(
        html,
        /No eligible claim matches the selected status and Panel/,
    );
    assert.match(html, /Eligible claims by status/);
    assert.match(html, />21<\/dd>/);
    assert.match(html, /Clear status/);
    assert.match(html, /Paid and rejected claims are not listed/);
});

test('actual handlers preserve both applied filters across paging and retain loaded controls on failed navigation', () => {
    const calls = [];
    const module = { exports: {} };
    const script = compile(
        compileScript(
            parse(
                readFileSync(
                    'resources/js/pages/PanelClaims/FollowUp.vue',
                    'utf8',
                ),
            ).descriptor,
            { id: 'followup-handlers' },
        ).content,
    );
    runInNewContext(script, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    router: { get: (...args) => calls.push(args) },
                    Head: container,
                    Link: container,
                };
            }

            if (name.startsWith('@/')) {
                return name === '@/types/billing'
                    ? money.exports
                    : new Proxy(container, {
                          get: (object, key) =>
                              key in object ? object[key] : container,
                      });
            }

            return require(name);
        },
    });
    const props = { filters: { panel: 'SYN', status: 'submitted' } };
    const handlers = module.exports.default.setup(props, { expose: () => {} });
    handlers.go('SYN', 2);
    assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1)[1])), {
        panel: 'SYN',
        status: 'submitted',
        page: 2,
    });
    const statusTarget = { value: 'queried' };
    handlers.changeStatus({ target: statusTarget });
    assert.equal(statusTarget.value, 'submitted');
    assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1)[1])), {
        panel: 'SYN',
        status: 'queried',
    });
    const panelTarget = { value: 'OTHER' };
    handlers.changePanel({ target: panelTarget });
    assert.equal(panelTarget.value, 'SYN');
    assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1)[1])), {
        panel: 'OTHER',
        status: 'submitted',
    });
    handlers.go('SYN', 1, '');
    assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1)[1])), {
        panel: 'SYN',
    });
});
