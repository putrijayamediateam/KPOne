import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
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
            readFileSync(
                'resources/js/pages/PanelPriceChanges/Index.vue',
                'utf8',
            ),
        ).descriptor,
        { id: 'panel-price-context', inlineTemplate: true },
    ).content,
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const proposal = {
    id: 'synthetic-external',
    panelName: 'Synthetic Panel with a very long agreement name',
    panelKey: '1',
    chargeId: 'charge1',
    chargeName: 'Synthetic medicine with a long formulation and strength',
    oldAmountSen: 1000,
    newAmountSen: 1250,
    reference: 'SYNTHETIC-AGREEMENT-LONG-2026',
    effectiveLabel: '1 Dec 2026, 12:00 AM',
    effectivePassed: false,
    status: 'proposed',
    proposedBy: 'Staff member',
    proposedAtLabel: '26 Nov 2026, 9:00 AM',
    decidedBy: null,
    decidedAtLabel: null,
    reasonLabel: null,
    appliedLabel: null,
    mine: false,
    lockVersion: 7,
};
async function render(overrides = {}, errors = {}) {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        crypto: { randomUUID },
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    usePage: () => ({ props: { errors } }),
                    router: {
                        get: () => assert.fail('SSR must not navigate'),
                        post: () => assert.fail('SSR must not mutate'),
                    },
                };
            }

            if (name === '@/types/billing') {
                return money.exports;
            }

            if (name === '@/components/ui/button') {
                return {
                    Button: {
                        setup:
                            (_, { slots }) =>
                            () =>
                                h('button', slots.default?.()),
                    },
                };
            }

            if (name === '@/components/ui/date-field') {
                return {
                    DateField: {
                        setup: () => () => h('input', { type: 'date' }),
                    },
                };
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
                return { CompactPagination: container };
            }

            if (name === '@/components/ui/state') {
                return {
                    EmptyState: {
                        props: ['title', 'description'],
                        setup: (p) => () => h('div', [p.title, p.description]),
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
                    branch: {
                        id: 1,
                        name: 'Synthetic branch',
                        timezone: 'Asia/Kuala_Lumpur',
                    },
                    canPropose: false,
                    canApprove: true,
                    panels: [],
                    charges: [],
                    selection: null,
                    waiting: [
                        proposal,
                        { ...proposal, id: 'synthetic-mine', mine: true },
                    ],
                    waitingCount: 52,
                    decided: [],
                    decidedPagination: { page: 1, lastPage: 3, total: 61 },
                    rejectReasons: { incorrect: 'Incorrect agreement' },
                    ...overrides,
                }),
        }),
    );
}

test('actual page distinguishes organisation totals from loaded proposals and keeps bulk four-eyes boundary', async () => {
    const html = await render();
    assert.match(html, /Organisation proposals/);
    assert.match(html, /Waiting for approval\s*<\/dt>\s*<dd[^>]*>\s*52/);
    assert.match(
        html,
        /Oldest waiting proposals loaded\s*<\/dt>\s*<dd[^>]*>\s*2/,
    );
    assert.match(html, /Decision page 1 of 3 contains 0 proposals/);
    assert.match(html, /Select all 1 that someone else proposed/);
    assert.match(
        html,
        /Select Synthetic Panel with a very long agreement name/,
    );
    assert.match(html, /SYNTHETIC-AGREEMENT-LONG-2026/);
    assert.match(html, /Staff member/);
    assert.match(html, /RM 10.00.*RM 12.50/s);
    assert.match(
        html,
        /Bills already built keep the price they were built with/,
    );
    assert.doesNotMatch(html, /href=".*[Aa]dmin|Approvals inbox/);
});
test('proposer keeps existing form and cannot gain approval controls from contextual guidance', async () => {
    const html = await render({ canPropose: true, canApprove: false });
    assert.match(html, /Propose price change/);
    assert.match(html, /Choose a Panel/);
    assert.match(html, /Someone other than the proposer must approve/);
    assert.doesNotMatch(html, /Select all 1|Approve selected \(/);
});
test('zero proposals and actual partial-refusal errors remain visible beside the unchanged context', async () => {
    const message =
        '4 price changes approved. 1 could not be approved. Review the refreshed lists.';
    const html = await render(
        {
            waiting: [],
            waitingCount: 0,
            decidedPagination: { page: 1, lastPage: 1, total: 0 },
        },
        { bulk: message },
    );
    assert.match(html, /No proposals are waiting/);
    assert.ok(html.includes(message));
    assert.match(html, /role="alert"/);
    assert.match(html, /Decision page 1 of 1 contains 0 proposals/);
    assert.doesNotMatch(html, /Approve selected \(/);
});
