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
    'resources/js/components/ui/page-header/PageHeader.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'branch-description',
    inlineTemplate: true,
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const render = async (branch, description, slots) => {
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        require: (name) =>
            name === '@inertiajs/vue3'
                ? {
                      usePage: () => ({
                          props: {
                              branchContext: branch
                                  ? { active: { name: branch } }
                                  : null,
                          },
                      }),
                  }
                : require(name),
    });

    return renderToString(
        createSSRApp({
            render: () =>
                h(
                    module.exports.default,
                    { title: 'Queue', description },
                    slots,
                ),
        }),
    );
};

test('identical plain branch description renders branch once with its marker', async () => {
    const html = await render('Synthetic Branch', 'Synthetic Branch');
    assert.equal((html.match(/Synthetic Branch/g) ?? []).length, 1);
    assert.match(html, /data-test="page-branch"/);
});

test('a different plain description remains alongside the active branch', async () => {
    const html = await render('Synthetic Branch', 'Waiting patients');
    assert.match(html, /Waiting patients/);
    assert.match(html, /Synthetic Branch/);
});

test('rich description slot remains even when plain fallback equals the branch', async () => {
    const html = await render('Synthetic Branch', 'Synthetic Branch', {
        description: () => h('strong', 'Clinical summary'),
    });
    assert.match(html, /<strong>Clinical summary<\/strong>/);
    assert.equal((html.match(/Synthetic Branch/g) ?? []).length, 1);
    assert.match(html, /data-test="page-branch"/);
});

test('description remains when no active branch is supplied', async () => {
    const html = await render(null, 'Synthetic Branch');
    assert.match(html, /Synthetic Branch/);
    assert.doesNotMatch(html, /data-test="page-branch"/);
});
