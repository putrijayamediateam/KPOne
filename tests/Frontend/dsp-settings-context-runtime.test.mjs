import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';
const source = readFileSync(
    'resources/js/pages/Dispensary/Settings.vue',
    'utf8',
);
const compiled = compileTemplate({
    source: parse(source).descriptor.template.content,
    filename: 'Settings.vue',
    id: 'dsp-settings-context',
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
async function render(overrides = {}) {
    const app = vue.createSSRApp({
        setup: () => ({
            branch: { id: 1, name: 'Synthetic Branch' },
            settingsChange: null,
            on: true,
            busy: false,
            errors: {},
            toggle: () => {},
            ...overrides,
        }),
        render: module.exports.render,
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Actual application component name in isolated renderer.
    app.component('Head', { render: () => null });
    app.component('PageHeader', {
        props: ['title', 'description'],
        setup: (props) => () =>
            vue.h('header', [props.title, props.description]),
    });
    app.component('InputError', {
        props: ['message'],
        setup: (props) => () =>
            props.message
                ? vue.h('p', { 'data-test': 'error' }, props.message)
                : null,
    });

    return renderToString(app);
}
test('authorized setting explanation does not invent last-change identities or navigation', async () => {
    const html = await render();
    assert.match(
        html,
        /Synthetic Branch · These settings apply to this branch only/,
    );
    assert.match(html, /Only staff authorized to manage this branch/);
    assert.match(html, /Changes are recorded/);
    assert.match(
        html,
        /already waiting for the doctor&#39;s verification stay waiting/,
    );
    assert.match(html, /Stock is taken out when the doctor verifies/);
    assert.match(html, /OTC visits are not affected/);
    assert.doesNotMatch(html, /<a |Follow-up/);
    assert.match(html, /Last changed/);
    assert.match(html, /Not available/);
});
test('real switch accessibility and busy/error state remain independent of explanatory content', async () => {
    const html = await render({
        on: false,
        busy: true,
        errors: { expected_branch_id: 'Synthetic branch changed' },
    });
    assert.match(
        html,
        /<button[^>]*role="switch"[^>]*aria-checked="false"[^>]*aria-labelledby="doctor-check-label"[^>]*aria-describedby="doctor-check-help"[^>]*disabled/,
    );
    assert.match(html, /Synthetic branch changed/);
    assert.match(html, />Off</);
    assert.match(html, /About this setting/);
});

test('attributed change renders only provided safe text, including a generic hidden-staff label', async () => {
    const html = await render({
        settingsChange: {
            changedAt: '27 Nov 2026, 7:30 AM',
            changedBy: 'Synthetic <Editor>',
        },
    });
    assert.match(html, /27 Nov 2026, 7:30 AM by Synthetic &lt;Editor&gt;/);
    assert.doesNotMatch(html, /<Editor>|Not available/);
    const hidden = await render({
        settingsChange: {
            changedAt: 'Synthetic time',
            changedBy: 'Staff member',
        },
    });
    assert.match(hidden, /Synthetic time by Staff member/);
});
