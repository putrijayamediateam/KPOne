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
            waitingCasesUrl: null,
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
    // eslint-disable-next-line vue/no-reserved-component-names -- Actual application component name in isolated renderer.
    app.component('Link', {
        setup:
            (_, { attrs, slots }) =>
            () =>
                vue.h('a', attrs, slots.default?.()),
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
test('compact switch retains the full target and semantic on/off visuals', async () => {
    const enabled = await render();
    assert.match(
        enabled,
        /role="switch"[^>]*aria-checked="true"[^>]*class="[^"]*h-11 w-11/,
    );
    assert.match(enabled, /class="[^"]*h-\[26px\][^"]*w-11[^"]*bg-success/);
    assert.match(enabled, /size-\[22px\][^"]*translate-x-\[18px\]/);
    assert.match(enabled, />On</);
    assert.doesNotMatch(enabled, /bg-brand/);
    const disabled = await render({ on: false, busy: true });
    assert.match(disabled, /aria-checked="false"[^>]*disabled/);
    assert.match(disabled, /bg-muted-foreground\/40/);
    assert.match(disabled, /translate-x-0/);
    assert.match(disabled, />Off</);
});
test('reduced motion applies to both track and knob while focus remains on the button', async () => {
    const html = await render();
    assert.equal(
        (html.match(/motion-reduce:transition-none/g) ?? []).length,
        2,
    );
    assert.match(html, /<button[^>]*focus-visible:ring-2/);
    assert.match(html, /data-testid="doctor-check-track"/);
});
