import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const component = { exports: {} };
const source = readFileSync(
    'resources/js/pages/Dispensary/partials/DispensaryRowContext.vue',
    'utf8',
);
const compiled = ts.transpileModule(
    compileScript(parse(source).descriptor, {
        id: 'row-context',
        inlineTemplate: true,
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
vm.runInNewContext(compiled, { exports: component.exports, require });
const render = (context, patientName = 'Synthetic Patient') =>
    renderToString(
        createSSRApp({
            render: () =>
                h(component.exports.default, { context, patientName }),
        }),
    );

test('actual native disclosure renders escaped saved facts and has no navigation or mutation controls', async () => {
    const html = await render(
        {
            visitReasonExcerpt: '<script>synthetic</script>',
            coverageLabel: 'Saved Panel & Group',
        },
        'Synthetic <Patient>',
    );
    assert.match(html, /<details/);
    assert.match(
        html,
        /<summary[^>]*aria-label="Visit details for Synthetic &lt;Patient&gt;"/,
    );
    assert.match(html, /&lt;script&gt;synthetic&lt;\/script&gt;/);
    assert.match(html, /Saved Panel &amp; Group/);
    assert.match(html, />\s*Reason\s*<\/dt>/);
    assert.match(html, />\s*Pays\s*<\/dt>/);
    assert.doesNotMatch(html, /<script|<button|<a\s|<form|<input/);
    assert.doesNotMatch(html, /<details[^>]*\sopen/);
});

test('missing saved facts are explicitly unknown and never infer self-pay', async () => {
    // Defensive component input only; panel-null is not claimed as a reachable PostgreSQL visit state.
    const html = await render({
        visitReasonExcerpt: null,
        coverageLabel: null,
    });
    assert.equal((html.match(/Not recorded/g) ?? []).length, 2);
    assert.doesNotMatch(html, /Self-pay/);
    const selfPay = await render({
        visitReasonExcerpt: 'Synthetic legacy reason',
        coverageLabel: 'Self-pay',
    });
    assert.match(selfPay, /Synthetic legacy reason/);
    assert.match(selfPay, /Self-pay/);
});
