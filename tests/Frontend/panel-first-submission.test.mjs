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
const source = readFileSync('resources/js/pages/PanelClaims/Index.vue', 'utf8');
const code = ts.transpileModule(
    compileScript(parse(source).descriptor, {
        id: 'first-submission',
        inlineTemplate: true,
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h(
                'div',
                Object.values(slots).flatMap((slot) => slot?.() ?? []),
            ),
};
async function render(
    empty = false,
    due = {
        dueLabel: '27 Nov 2026',
        ruleLabel: 'Submit on the day of the visit',
        overdue: false,
    },
) {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    Head: container,
                    usePage: () => ({ props: {} }),
                    router: {},
                };
            }

            if (name === '@/components/ui/state') {
                return {
                    EmptyState: {
                        props: ['title', 'description'],
                        setup: (p) => () =>
                            h('section', [
                                h('h2', p.title),
                                h('p', p.description),
                            ]),
                    },
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
    const page = { data: [], total: 0, currentPage: 1, lastPage: 1 };
    const row = {
        publicId: 'synthetic-claim',
        claimNo: 'SYN-1',
        patientName: 'Synthetic Waiting Patient',
        panelName: 'Synthetic Panel',
        panelId: 1,
        invoiceNumber: 'SYN-INV',
        visitNumber: 'SYN-VISIT',
        claimAmountSen: 3000,
        status: 'Prepared',
        overdue: false,
        createdAt: '26 Nov 2026',
        updatedBy: null,
        remarks: null,
        showUrl: '/panel-claims/synthetic-claim',
        submissionDue: due,
    };
    const work = {
        branch: { id: 1, name: 'Synthetic branch', today: '2026-11-26' },
        filters: { tab: 'not_submitted', range: 'all', panel: '' },
        tabs: { not_submitted: empty ? 0 : 21 },
        panels: [],
        can: { recordRemittances: false },
        waiting: 0,
        eligible: page,
        claims: empty ? page : { ...page, data: [row], total: 21, lastPage: 2 },
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

    return renderToString(
        createSSRApp({ render: () => h(module.exports.default, { work }) }),
    );
}

test('actual card and table show first-submission due context without changing payout summaries', async () => {
    const html = await render();
    assert.equal((html.match(/Due 27 Nov 2026/g) ?? []).length, 2);
    assert.match(html, /Prepared claims with no recorded submission/);
    assert.match(html, /current submission rule/);
    assert.match(html, /date range uses claim creation/);
    assert.match(html, /Created \/ due/);
    assert.doesNotMatch(html, /12:00 AM|Ready to submit/);
    const payouts = html.match(/aria-label="Payouts"[\s\S]*?<\/section>/)?.[0];
    assert.ok(payouts);
    assert.doesNotMatch(payouts, /Not yet submitted|Due 27/);
});

test('timed due labels stay explicit and missing context is honestly unavailable', async () => {
    const timed = await render(false, {
        dueLabel: '28 Nov 2026, 9:00 AM',
        ruleLabel: 'Submit by 9:00 AM the day after the visit',
        overdue: false,
    });
    assert.equal((timed.match(/Due 28 Nov 2026, 9:00 AM/g) ?? []).length, 2);
    const missing = await render(false, null);
    assert.equal(
        (missing.match(/Submission deadline unavailable/g) ?? []).length,
        2,
    );
    assert.doesNotMatch(missing, /Due 27 Nov|9:00 AM/);
});

test('empty first-submission view describes its exact eligibility without suggesting a payment action', async () => {
    const html = await render(true);
    assert.match(
        html,
        /No Prepared claims awaiting their first recorded submission match these filters/,
    );
    assert.doesNotMatch(html, /Due 27 Nov|Submission deadline unavailable/);
});
