import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const source = readFileSync('resources/js/pages/Dispensary/Labels.vue', 'utf8');
const header = source.match(
    /<header class="print-toolbar">[\s\S]*?<\/header>/,
)?.[0];
assert.ok(header);
const compiled = compileTemplate({
    source: header,
    filename: 'LabelToolbar.vue',
    id: 'label-toolbar-runtime',
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
    const state = {
        labels: {
            patient: { name: 'Synthetic Label Patient' },
            branch: 'Synthetic Branch',
            items: [{}, {}],
            blocked: [{}],
            excludedCount: 3,
            pageSize: { width: 100, height: 62 },
            date: 'Synthetic date',
        },
        changed: false,
        retired: false,
        canPrint: true,
        reprintCount: 1,
        busy: false,
        error: '',
        printLabels: () => {},
        ...overrides,
    };
    const app = vue.createSSRApp({
        setup: () => state,
        render: module.exports.render,
    });
    // eslint-disable-next-line vue/no-reserved-component-names -- Register the existing app component name in this isolated renderer.
    app.component('Button', {
        setup:
            (_, { slots, attrs }) =>
            () =>
                vue.h('button', attrs, slots.default?.()),
    });

    return renderToString(app);
}

test('real categories stay separate and mixed blocked selection cannot print', async () => {
    const html = await render();

    for (const [label, count] of [
        ['Prepared labels', 2],
        ['Blocked lines', 1],
        ['Excluded lines', 3],
        ['Previously printed labels', 1],
    ]) {
        assert.match(html, new RegExp(`<dt>${label}</dt><dd>${count}</dd>`));
    }

    assert.match(html, /Synthetic Label Patient · Synthetic Branch/);
    assert.match(html, /<button[^>]*disabled/);
});

test('changed or retired pages hide added patient identity and counts', async () => {
    for (const guard of [{ changed: true }, { retired: true }]) {
        const html = await render(guard);
        assert.doesNotMatch(
            html,
            /Synthetic Label Patient|Synthetic Branch|Label status|Prepared labels/,
        );
        assert.match(html, /Reload this visit/);
        assert.match(html, /<button[^>]*disabled/);
    }
});

test('readonly retains existing context but no print instruction, button or added print-history count', async () => {
    const html = await render({ canPrint: false });
    assert.match(html, /Synthetic Label Patient/);
    assert.match(html, /View only\. Printing is done by the CA/);
    assert.doesNotMatch(
        html,
        /<button|Previously printed labels|Print at 100%/,
    );
});
