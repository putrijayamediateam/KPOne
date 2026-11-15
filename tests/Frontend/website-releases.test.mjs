import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';
const read = (file) =>
    readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Website/Releases.vue');
const value = read(
    'resources/js/components/website/ReleaseStructuredValue.vue',
);
test('Private release components compile with exact hash confirmation and responsive structured content', () => {
    for (const [filename, source] of [
        ['Releases.vue', page],
        ['ReleaseStructuredValue.vue', value],
    ]) {
        const descriptor = parse(source).descriptor;
        const script = compileScript(descriptor, { id: filename });
        assert.deepEqual(
            compileTemplate({
                id: filename,
                source: descriptor.template.content,
                filename,
                compilerOptions: { bindingMetadata: script.bindings },
            }).errors,
            [],
        );
    }

    assert.match(page, /Nothing is public/);
    assert.match(page, /not future edits/);
    assert.match(page, /form.confirmation !== page.detail.number/);
    assert.match(page, /v-if="page.canApprove"/);
    assert.match(page, /page.detail.state === 'prepared'/);
    assert.match(page, /action.action === 'approved'/);
    assert.match(page, /lg:grid-cols-2/);
    assert.match(page, /break-all/);
    assert.match(page, /max-w-7xl min-w-0/);
    assert.match(page, /noindex,nofollow/);
    assert.doesNotMatch(
        page + value,
        /v-html|iframe|window.open|fetch\(|https:\/\/|publish\(|deploy\(/,
    );
});
test('Branch switch clears frozen content, confirmation and reason before reload; no unbounded public route', () => {
    assert.match(page, /router.on\('before'/);
    assert.match(page, /page.value = null/);
    assert.match(page, /confirmation: '',\s*reason: ''/);
    assert.match(page, /form.clearErrors/);
    assert.match(page, /onUnmounted\(remove\)/);
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /website\/releases.*WebsiteReleaseController.*sensitive.no-store:no-referrer/,
    );
    assert.match(routes, /permission:website.releases.prepare.organisation/);
    assert.match(routes, /permission:website.releases.approve.organisation/);
    assert.match(
        read('app/Http/Controllers/WebsiteReleaseController.php'),
        /redirect\(\)->route\('website.releases.show'/,
    );
    assert.doesNotMatch(
        read('app/Domain/Organisation/Website/WebsiteReleaseService.php'),
        /Http::|dispatch\(|schedule\(|User::query|Patient::/,
    );
});
test('Structured saved preview actually escapes text and retains list order', async () => {
    const require = createRequire(import.meta.url);
    const compiled = compileScript(parse(value).descriptor, {
        id: 'structured',
        inlineTemplate: true,
    });
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(compiled.content, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, require },
    );
    const component = module.exports.default;
    const app = createSSRApp({
        render: () =>
            h(component, {
                value: {
                    title: '<script>unsafe</script>',
                    steps: ['Second step', 'First step'],
                },
            }),
    });
    app.component('ReleaseStructuredValue', component);
    const html = await renderToString(app);
    assert.match(html, /&lt;script&gt;unsafe&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>/);
    assert.ok(html.indexOf('Second step') < html.indexOf('First step'));
});
