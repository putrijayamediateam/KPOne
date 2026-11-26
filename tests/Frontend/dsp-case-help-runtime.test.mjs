import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

// Render the actual header/panel markup, including its real label guards and readonly copy.
const source = readFileSync('resources/js/pages/Dispensary/Show.vue', 'utf8');
const headers = [
    ...source.matchAll(/<PageHeader\b[\s\S]*?<\/PageHeader>/g),
].map((match) => match[0]);
const panel = source.match(
    /<section\s+id="dispensary-helpful-info"[\s\S]*?<\/section>/,
)?.[0];
assert.equal(headers.length, 2);
assert.ok(panel);
const compiled = compileTemplate({
    source: `${headers.join('\n')}\n${panel}`,
    filename: 'ShowHelp.vue',
    id: 'case-help-runtime',
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
    readonly = false,
    preview = false,
    print = false,
    dirty = false,
    shown = true,
    otc = false,
} = {}) {
    const state = {
        dispensary: {
            publicId: 'synthetic-case',
            viewOnly: readonly,
            canPreviewLabels: preview,
            canPrintLabels: print,
        },
        visibleItems: [{}],
        unsaved: dirty ? [{}] : [],
        unsavedServices: [],
        isOtc: otc,
        helpfulInfoOpen: vue.ref(shown),
    };
    const app = vue.createSSRApp({
        setup: () => state,
        render: module.exports.render,
    });
    app.component('PageHeader', {
        setup:
            (_, { slots }) =>
            () =>
                vue.h('header', slots.actions?.()),
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Match the existing app component name in this isolated renderer.
    app.component('Button', {
        setup:
            (_, { slots, attrs }) =>
            () =>
                vue.h('button', attrs, slots.default?.()),
    });

    for (const name of ['Eye', 'Printer']) {
        app.component(name, { render: () => vue.h('span') });
    }

    return renderToString(app);
}

test('readonly help is available without granting preview or printing and has no save instruction', async () => {
    const html = await render({ readonly: true });
    assert.equal(
        (html.match(/data-testid="case-help-toggle"/g) ?? []).length,
        1,
    );
    assert.match(html, /This view does not allow changes/);
    assert.doesNotMatch(html, /href=|Save edited lines|Print all labels/);
    assert.match(
        html,
        /aria-expanded="true" aria-controls="dispensary-helpful-info"/,
    );
});

test('help coexists with the exact preview/print/dirty guards', async () => {
    const viewer = await render({ readonly: true, preview: true });
    assert.match(viewer, /data-testid="view-labels"/);
    assert.doesNotMatch(viewer, /Print all labels/);
    assert.match(await render({ print: true }), /Print all labels/);
    const dirty = await render({ print: true, dirty: true });
    assert.doesNotMatch(dirty, /Print all labels|href=/);
    assert.match(dirty, /data-testid="case-help-toggle"/);
});

test('hiding help keeps its mounted details while aria-expanded and text reflect the state', async () => {
    const html = await render({ shown: false });
    assert.match(html, /aria-expanded="false"/);
    assert.match(html, /Show helpful info/);
    assert.match(
        html,
        /id="dispensary-helpful-info"[^>]*style="display:none;"/,
    );
    assert.match(html, /<details open/);
    assert.match(html, /Good to know/);
});

test('OTC help describes the existing allergy confirmation rather than a doctor order', async () => {
    const html = await render({ otc: true });
    assert.match(html, /confirm what the patient reports about allergies/);
    assert.doesNotMatch(html, /Review the doctor/);
    assert.match(html, /does not save or dispense anything/);
});
