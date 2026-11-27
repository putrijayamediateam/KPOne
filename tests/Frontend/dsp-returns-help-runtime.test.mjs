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
        id: 'returns-help',
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

const render = (overrides = {}) =>
    renderToString(
        vue.createSSRApp({
            render: () =>
                vue.h(component.exports.default, {
                    returns: {
                        branchName: 'Synthetic Branch',
                        view: 'waiting',
                        rows: [],
                        page: 1,
                        lastPage: 1,
                        total: 0,
                        ...overrides,
                    },
                }),
        }),
    );

test('optional help does not contain the mandatory waiting instructions or inline check actions', async () => {
    const html = await render({
        counts: { waiting: 0, checked: 7 },
        monthLabel: 'November 2026',
    });
    const aside = html.match(/<aside[\s\S]*?<\/aside>/)?.[0];
    assert.ok(aside);
    assert.match(
        html.slice(0, html.indexOf('<aside')),
        /You cannot check a return you recorded/,
    );
    assert.match(html, /aria-controls="returns-helpful-info"/);
    assert.match(aside, /At a glance/);
    assert.match(aside, /selected age filter/);
    assert.match(aside, /all outcomes/);
    assert.match(aside, />0<\/dd>/);
    assert.doesNotMatch(aside, /<form|Save check|Mark checked/);
});

test('checked help labels the actual month and outcome scope without inventing today counts', async () => {
    const html = await render({
        view: 'checked',
        counts: { waiting: 12, checked: 3 },
        monthLabel: 'November 2026',
    });
    const aside = html.match(/<aside[\s\S]*?<\/aside>/)?.[0];
    assert.match(aside, /all ages/);
    assert.match(aside, /November 2026/);
    assert.match(aside, /selected outcome filter/);
    assert.match(aside, /selected medicine filter/);
    assert.doesNotMatch(aside, /Today at a glance|Save check/);
});

test('missing counts omit the optional summary instead of showing invented zeros', async () => {
    const html = await render();
    assert.doesNotMatch(html, /At a glance/);
    assert.match(html, /About this tab/);
    assert.match(html, /Controlled medicines cannot be quarantined/);
});
