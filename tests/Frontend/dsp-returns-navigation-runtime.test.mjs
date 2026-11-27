import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const require = createRequire(import.meta.url);
const descriptor = parse(
    readFileSync('resources/js/pages/Dispensary/ReturnsToCheck.vue', 'utf8'),
).descriptor;
const code = ts.transpileModule(
    compileScript(descriptor, {
        id: 'returns-navigation',
        inlineTemplate: true,
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const blank = { render: () => null };
const link = {
    props: ['href'],
    setup:
        (props, { attrs, slots }) =>
        () =>
            vue.h('a', { ...attrs, href: props.href }, slots.default?.()),
};
const component = { exports: {} };
vm.runInNewContext(code, {
    exports: component.exports,
    require: (name) => {
        if (name === '@inertiajs/vue3') {
            return { Head: blank, router: {} };
        }

        if (name === '@/components/ui/action-link') {
            return { ActionLink: link };
        }

        if (name.startsWith('@/components/')) {
            return new Proxy(
                {},
                { get: (_, key) => (key === '__esModule' ? true : blank) },
            );
        }

        if (name === '@/lib/dispensary-returns') {
            return {
                refuseReasonLabels: {},
                returnConditionLabels: {},
                returnReasonLabels: {},
            };
        }

        return require(name);
    },
});

for (const view of ['waiting', 'checked']) {
    test(`actual empty ${view} page keeps workspace navigation and does not transfer return filters`, async () => {
        const html = await renderToString(
            vue.createSSRApp({
                render: () =>
                    vue.h(component.exports.default, {
                        returns: {
                            branchName: 'Synthetic Branch',
                            view,
                            controlled: 'yes',
                            outcome: 'dispose',
                            waitingAge: '3days',
                            monthLabel: 'November2026',
                            counts: { waiting: 0, checked: 0 },
                            rows: [],
                            page: 1,
                            lastPage: 1,
                            total: 0,
                        },
                    }),
            }),
        );
        const nav = html.match(
            /<nav[^>]*aria-label="Dispensary workspace"[\s\S]*?<\/nav>/,
        )?.[0];
        assert.ok(nav);
        assert.match(nav, /href="\/dispensary"/);
        assert.match(nav, />\s*Dispensing\s*<\/a>/);
        assert.match(
            nav,
            /<span[^>]*aria-current="page"[^>]*>\s*Returns\s*<\/span>/,
        );
        assert.doesNotMatch(
            nav,
            /waiting_age|controlled=|outcome=|view=|<button|<form/,
        );
    });
}
