import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const source = readFileSync(
    'resources/js/pages/Dispensary/Completed.vue',
    'utf8',
);
const compiled = compileTemplate({
    source: parse(source).descriptor.template.content,
    filename: 'Completed.vue',
    id: 'completed-context-runtime',
});
assert.deepEqual(compiled.errors, []);
const module = { exports: {} };
vm.runInNewContext(
    ts.transpileModule(compiled.code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, require: () => vue },
);
async function render({ dispensary = {}, returns: panel } = {}) {
    const app = vue.createSSRApp({
        setup: () => ({
            dispensary: {
                status: 'completed',
                patient: {
                    name: 'Synthetic Long Patient',
                    patientNumber: 'KP-SYNTHETIC',
                },
                visit: { visitNumber: 'KPV-SYNTHETIC' },
                completedAt: null,
                ...dispensary,
            },
            returns: panel,
            supervisorCheck: null,
        }),
        render: module.exports.render,
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Existing app component name in isolated renderer.
    app.component('Head', { render: () => null });
    app.component('PageHeader', {
        props: ['title', 'description'],
        setup:
            (props, { slots }) =>
            () =>
                vue.h('header', [
                    props.title,
                    props.description,
                    slots.actions?.(),
                ]),
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Actual app component registration in isolated template renderer.
    app.component('Button', {
        inheritAttrs: false,
        setup:
            (_, { slots }) =>
            () =>
                slots.default?.(),
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Existing app component name in isolated renderer.
    app.component('Link', {
        setup:
            (_, { attrs, slots }) =>
            () =>
                vue.h('a', attrs, slots.default?.()),
    });
    app.component('ReadOnlyBanner', {
        props: ['message'],
        setup: (props) => () => vue.h('p', props.message),
    });
    app.component('MedicineReturnsSection', {
        props: ['returns', 'viewOnly'],
        setup: (props) => () =>
            vue.h(
                'section',
                { 'data-return-view-only': String(!!props.viewOnly) },
                'Returns child',
            ),
    });

    for (const name of ['SupervisorCheckNote', 'ArrowLeft', 'Eye']) {
        app.component(name, { render: () => null });
    }

    return renderToString(app);
}

test('completion card keeps real timestamp and conditional return guidance', async () => {
    const html = await render({
        dispensary: { completedAt: 'Synthetic completion time' },
        returns: { lines: [{}] },
    });
    assert.match(html, /Completion status/);
    assert.match(html, /Completed Synthetic completion time/);
    assert.match(html, /If the patient brings medicine back/);
    assert.match(html, /data-return-view-only="false"/);
    const empty = await render();
    assert.match(empty, /Fulfilment has finished/);
    assert.doesNotMatch(
        empty,
        /Completed null|If the patient brings medicine back|Returns child/,
    );
});

test('readonly keeps authorized preview/back and readonly return child without CA guidance', async () => {
    const html = await render({
        dispensary: {
            viewOnly: true,
            labelUrl: '/synthetic-labels',
            backLink: {
                href: '/synthetic-encounter',
                label: 'Return to consultation',
            },
        },
        returns: { lines: [{}] },
    });
    assert.match(html, /href="\/synthetic-labels"/);
    assert.match(html, /View labels/);
    assert.match(html, /href="\/synthetic-encounter"/);
    assert.match(html, /data-return-view-only="true"/);
    assert.doesNotMatch(
        html,
        /Completion status|Fulfilment has finished|Print current labels|Continue to Billing/,
    );
});

test('existing optional billing and label actions remain payload-controlled', async () => {
    const html = await render({
        dispensary: {
            billingUrl: '/synthetic-billing',
            labelUrl: '/synthetic-labels',
        },
    });
    assert.match(html, /href="\/synthetic-billing"/);
    assert.match(html, /Print current labels/);
    assert.match(html, /href="\/registration"/);
    const absent = await render();
    assert.doesNotMatch(
        absent,
        /Continue to Billing|Print current labels|Follow-up/,
    );
});
