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

test('actual page separates filtered total and loaded claims without combining claimed and unpaid money', async () => {
    const html = await render();
    assert.match(html, /Matching claims\s*<\/dt>\s*<dd[^>]*>21/);
    assert.match(html, /Claims on this page\s*<\/dt>\s*<dd[^>]*>2/);
    assert.match(html, /Page 1 of 2/);
    assert.match(html, /Counts follow the current branch and Panel filter/);
    assert.match(html, /Submitted · Claimed, awaiting decision RM 150.00/);
    assert.match(html, /Accepted, unpaid · Unpaid RM 0.00/);
    assert.match(html, /Staff member/);
    assert.match(html, /Supplementary to PCL-SYN-PARENT/);
    assert.match(html, /href="\/panel-claims\/synthetic-claim"/);
    assert.match(html, /56 days, no action/);
    assert.doesNotMatch(html, /Total owed|Total unpaid|Total claimed/);
});

test('filtered empty state remains scoped and keeps authoritative guidance outside optional details', async () => {
    const html = await render({
        filters: { panel: 'SYN' },
        claims: { data: [], total: 0, currentPage: 1, lastPage: 1 },
    });
    assert.match(
        html,
        /No claim for the selected Panel needs a follow-up right now/,
    );
    assert.match(html, /Selected Panel/);
    assert.match(html, /Paid and rejected claims are not listed/);
    assert.match(
        html,
        /Prepared claims that have never been submitted are on Not yet submitted/,
    );
    assert.ok(html.indexOf('never been submitted') < html.indexOf('<details'));
    assert.doesNotMatch(html, /aria-label="Pagination"/);
    assert.doesNotMatch(html, /href="[^\"]*overdue/);
});

test('unfiltered empty state and long identifiers remain readable in the actual page', async () => {
    const empty = await render({
        claims: { data: [], total: 0, currentPage: 1, lastPage: 1 },
    });
    assert.match(empty, /No claim needs a follow-up right now/);
    assert.match(empty, /All Panels/);
    const populated = await render();
    assert.match(populated, /Synthetic patient with a long display name/);
    assert.match(populated, /SYN-LONG-CLAIM-123/);
    assert.match(populated, /INV-SYN-123/);
    assert.match(populated, /focus-visible:ring-2/);
});
