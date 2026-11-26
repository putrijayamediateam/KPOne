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
const moneyModule = { exports: {} };
runInNewContext(
    compile(readFileSync('resources/js/types/billing.ts', 'utf8')),
    { exports: moneyModule.exports },
);
const code = compile(
    compileScript(
        parse(
            readFileSync('resources/js/pages/FinancialWork/Index.vue', 'utf8'),
        ).descriptor,
        { id: 'financial-work', inlineTemplate: true },
    ).content,
);
const container = {
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
};
const module = { exports: {} };
runInNewContext(code, {
    exports: module.exports,
    require: (name) => {
        if (name === '@inertiajs/vue3') {
            return {
                Head: container,
                router: {
                    get: () => assert.fail('Rendering must not navigate'),
                },
            };
        }

        if (name === '@/types/billing') {
            return moneyModule.exports;
        }

        if (name === '@/components/ui/action-link') {
            return {
                ActionLink: {
                    props: ['href'],
                    setup:
                        (props, { slots }) =>
                        () =>
                            h('a', { href: props.href }, slots.default?.()),
                },
            };
        }

        if (name === '@/components/ui/page-header') {
            return {
                PageHeader: {
                    props: ['title', 'description'],
                    setup:
                        (props, { slots }) =>
                        () =>
                            h('header', [
                                h('h1', props.title),
                                h('p', props.description),
                                slots.actions?.(),
                            ]),
                },
            };
        }

        if (name === '@/components/ui/pagination') {
            return { CompactPagination: container };
        }

        if (name === '@/components/ui/status') {
            return {
                StatusBadge: {
                    props: ['status'],
                    setup: (props) => () => h('span', props.status),
                },
            };
        }

        if (name === '@/components/ui/state') {
            return {
                EmptyState: {
                    props: ['title', 'description'],
                    setup: (props) => () =>
                        h('div', [props.title, props.description]),
                },
            };
        }

        if (name === '@/components/ui/table') {
            return {
                OperationalTable: {
                    setup:
                        (_, { slots }) =>
                        () =>
                            h('table', [
                                h('thead', slots.head?.()),
                                h('tbody', slots.body?.()),
                            ]),
                },
            };
        }

        return require(name);
    },
});
const row = {
    invoiceNumber: 'KPI-SYNTHETIC-LONG-00000000000000001',
    visitNumber: 'KPV-SYNTHETIC-00000000000000001',
    patientName: 'Synthetic Long Financial Review Patient',
    branch: 'Synthetic branch',
    status: 'finalized',
    reviewUrl: '/synthetic-authorised-review/one',
};
async function render(mode, data) {
    return renderToString(
        createSSRApp({
            render: () =>
                h(module.exports.default, {
                    work: {
                        mode,
                        branch: 'Synthetic branch',
                        data,
                        total: data.length,
                        currentPage: 1,
                        lastPage: 1,
                    },
                }),
        }),
    );
}
const card = (html) =>
    html.match(
        /<article[^>]*data-testid="financial-work-card"[^>]*>([\s\S]*?)<\/article>/,
    )?.[1] ?? assert.fail('Expected an actual rendered mobile card');
const value = (html, label, amount) =>
    assert.match(
        html,
        new RegExp(`<dt[^>]*>${label}<\\/dt>\\s*<dd[^>]*>${amount}<\\/dd>`),
    );

test('finance card retains all six distinct money labels and the existing review destination', async () => {
    const html = await render('finance', [
        {
            ...row,
            state: {
                total: 4000,
                self_pay: 1000,
                panel: 500,
                deferred: 1000,
                due_now: 1500,
            },
        },
    ]);
    const content = card(html);

    for (const [label, amount] of [
        ['Invoice Total', 'RM 40.00'],
        ['Received', 'RM 10.00'],
        ['Panel', 'RM 5.00'],
        ['Pay Later', 'RM 10.00'],
        ['Outstanding', 'RM 25.00'],
        ['Due Now', 'RM 15.00'],
    ]) {
        value(content, label, amount);
    }

    assert.ok(content.includes(row.patientName));
    assert.ok(content.includes(row.visitNumber));
    assert.ok(content.includes(row.invoiceNumber));
    assert.match(content, /href="\/synthetic-authorised-review\/one"/);
    assert.doesNotMatch(html, /<form|To pay|Record payment/);
});

test('panel card preserves zero responsibility and requested time without finance-only fields', async () => {
    const html = await render('panel', [
        { ...row, amountSen: 0, requestedAt: '27 Nov 2026, 9:00 am' },
    ]);
    const content = card(html);
    value(content, 'Responsibility', 'RM 0.00');
    value(content, 'Requested', '27 Nov 2026, 9:00 am');
    assert.doesNotMatch(content, /Invoice Total|Received|Outstanding|Due Now/);
    assert.match(
        html,
        /Claims submission is not\s+available in this workspace/,
    );
    assert.doesNotMatch(html, /<form/);
});

test('zero finance values remain visible and both empty modes expose no card or mutation form', async () => {
    const content = card(
        await render('finance', [
            {
                ...row,
                state: {
                    total: 0,
                    self_pay: 0,
                    panel: 0,
                    deferred: 0,
                    due_now: 0,
                },
            },
        ]),
    );
    assert.equal((content.match(/RM 0\.00/g) ?? []).length, 6);

    for (const [mode, message] of [
        ['finance', 'No Finance work'],
        ['panel', 'No Panel reviews'],
    ]) {
        const html = await render(mode, []);
        assert.ok(html.includes(message));
        assert.doesNotMatch(html, /financial-work-card|<form/);
    }
});
