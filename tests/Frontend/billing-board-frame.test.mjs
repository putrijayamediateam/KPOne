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
const source = readFileSync(
    'resources/js/components/billing/BillingBoardFrame.vue',
    'utf8',
);
const code = ts.transpileModule(
    compileScript(parse(source).descriptor, {
        id: 'billing-board',
        inlineTemplate: true,
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
async function render(permissions, active = 'collect') {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        require: (name) =>
            name === '@inertiajs/vue3'
                ? {
                      usePage: () => ({ props: { auth: { permissions } } }),
                      Link: {
                          props: ['href'],
                          setup:
                              (props, { slots }) =>
                              () =>
                                  h(
                                      'a',
                                      { href: props.href },
                                      slots.default?.(),
                                  ),
                      },
                  }
                : name === '@/components/ui/page-frame'
                  ? {
                        PageFrame: {
                            setup:
                                (_, { slots }) =>
                                () =>
                                    h('div', slots.default?.()),
                        },
                    }
                  : require(name),
    });

    return renderToString(
        createSSRApp({
            render: () =>
                h(
                    module.exports.default,
                    {
                        active,
                        about: 'Existing branch bills only',
                        guidance: 'Payment is recorded on billing',
                    },
                    {
                        default: () => h('p', 'Real server rows'),
                        context: () => h('p', 'RM 12.34'),
                    },
                ),
        }),
    );
}
test('collection-only access does not advertise cash or terminal routes', async () => {
    const html = await render(['outstanding.view.branch']);
    assert.match(html, /href="\/pay-later-collections" aria-current="page"/);
    assert.doesNotMatch(
        html,
        /href="\/cash-closings|href="\/payment-reconciliations/,
    );
    assert.match(html, /Real server rows/);
    assert.match(html, /RM 12.34/);
});
test('cash view access advertises daily and monthly viewing but not terminal reconciliation', async () => {
    const html = await render(['payments.cash_closing.view.branch'], 'close');
    assert.match(html, /href="\/cash-closings" aria-current="page"/);
    assert.match(html, /href="\/cash-closings\/month"/);
    assert.doesNotMatch(
        html,
        /href="\/pay-later-collections|href="\/payment-reconciliations/,
    );
});
test('reconciliation access includes terminal and native keyboard-operable disclosure content', async () => {
    const html = await render(['payments.reconcile.branch'], 'close');
    assert.match(html, /href="\/payment-reconciliations"/);
    assert.match(html, /<details open/);
    assert.match(html, /<summary[^>]*>\s*About this tab\s*<\/summary>/);
    assert.match(html, /Payment is recorded on billing/);
    assert.doesNotMatch(html, /<form|<button|role="tab"/);
});
