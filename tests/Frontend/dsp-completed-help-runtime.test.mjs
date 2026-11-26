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
async function render({
    dispensary = {},
    returns: panel,
    showHelpfulInfo = false,
} = {}) {
    const app = vue.createSSRApp({
        setup: () => ({
            showHelpfulInfo,
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

test('hidden optional guidance does not hide required completion or returns content', async () => {
    const html = await render({ returns: { lines: [{}] } });
    assert.match(html, /Fulfilment has finished/);
    assert.match(html, /Returns child/);
    assert.match(html, /id="completed-helpful-info"[^>]*style="display:none;"/);
    assert.ok(
        html.indexOf('Returns child') <
            html.indexOf('id="completed-helpful-info"'),
    );
});

test('shown guidance describes current separation rules without adding navigation or mutation', async () => {
    const html = await render({ showHelpfulInfo: true });
    assert.doesNotMatch(html, /id="completed-helpful-info"[^>]*display:none/);
    assert.match(html, /About this page/);
    assert.match(html, /Good to know/);
    assert.match(html, /does not issue a refund/);
    assert.match(html, /authorized checker who did not record the return/);
    assert.match(html, /Controlled medicines cannot be quarantined/);
    const help = html.slice(html.indexOf('id="completed-helpful-info"'));
    assert.doesNotMatch(help, /<a |<form|Follow-up/);
});

test('readonly guidance describes authorized staff without instructing the doctor to act', async () => {
    const html = await render({
        showHelpfulInfo: true,
        dispensary: { viewOnly: true },
    });
    assert.match(html, /Changes and printing are handled by authorized staff/);
    assert.match(html, /View only. You cannot change this case/);
    assert.doesNotMatch(
        html,
        /Use the available actions|Print current labels|Continue to Billing/,
    );
});
