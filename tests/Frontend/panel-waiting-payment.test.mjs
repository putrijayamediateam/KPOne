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
        id: 'waiting-payment',
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
async function render(empty = false) {
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
        status: 'Partially paid',
        overdue: false,
        createdAt: '26 Nov 2026',
        updatedBy: null,
        remarks: null,
        showUrl: '/panel-claims/synthetic-claim',
        paymentBalance: {
            acceptedSen: 2501,
            receivedSen: 1001,
            outstandingSen: 1500,
        },
    };
    const work = {
        branch: { id: 1, name: 'Synthetic branch', today: '2026-11-26' },
        filters: { tab: 'waiting_payment', range: 'all', panel: '' },
        tabs: { waiting_payment: empty ? 0 : 21 },
        panels: [],
        can: { recordRemittances: false },
        waiting: 0,
        eligible: page,
        claims: empty ? page : { ...page, data: [row], total: 21, lastPage: 2 },
        waitingPayment: empty
            ? { count: 0, acceptedSen: 0, receivedSen: 0, outstandingSen: 0 }
            : {
                  count: 21,
                  acceptedSen: 52521,
                  receivedSen: 21021,
                  outstandingSen: 31500,
              },
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

test('actual full page renders separate matching totals and exact card/table balances', async () => {
    const html = await render();
    assert.match(html, /21 matching claims/);

    for (const amount of [
        '525.21',
        '210.21',
        '315.00',
        '30.00',
        '25.01',
        '10.01',
        '15.00',
    ]) {
        assert.ok(html.includes(`RM ${amount}`));
    }

    assert.equal(
        (html.match(/aria-label="Claim payment balance"/g) ?? []).length,
        2,
    );

    for (const label of ['Claimed', 'Accepted', 'Received', 'Unpaid']) {
        assert.ok(html.includes(label));
    }

    const payouts = html.match(/aria-label="Payouts"[\s\S]*?<\/section>/)?.[0];
    assert.ok(payouts);
    assert.doesNotMatch(payouts, /525\.21|315\.00|Waiting for payment/);
});

test('empty matching set retains zero totals and truthful date and amount-filter explanations', async () => {
    const html = await render(true);
    assert.match(html, /0 matching claims/);
    assert.match(html, /creation date/);
    assert.match(html, /excluding reversed payments/);
    assert.match(html, /not receipts during the selected period/);
    assert.match(html, /Amount filter uses the claimed amount/);
    assert.doesNotMatch(
        html,
        /Synthetic Waiting Patient|Claim payment balance/,
    );
});
