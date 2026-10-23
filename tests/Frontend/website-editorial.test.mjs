import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/Website/Editorial.vue');
const preview = read(
    'resources/js/components/website/EditorialDraftPreview.vue',
);

test('Promotions, activities and FAQs compile with bounded fields and escaped saved preview', () => {
    for (const [name, text] of [
        ['Editorial.vue', source],
        ['EditorialDraftPreview.vue', preview],
    ]) {
        assert.deepEqual(
            compileTemplate({
                id: name,
                source: parse(text).descriptor.template.content,
                filename: name,
            }).errors,
            [],
        );
        assert.doesNotMatch(
            text,
            /v-html|fetch\(|axios|window\.open|iframe|type="file"/,
        );
    }

    for (const text of [
        'Nothing is public yet',
        'manual',
        'offer',
        'guaranteed-outcome',
        'patient data',
        'Incomplete',
        ':maxlength="limit"',
        'description_sections',
        'gallery_public_ids',
        'Move up',
        'Move down',
        'up to 12',
        'Saved draft preview',
        'item-wide',
        'YYYY-MM-DD',
        'Previous',
        'Previously selected asset',
        'No cover',
    ]) {
        assert.ok((source + preview).replace(/\s+/g, ' ').includes(text), text);
    }
});
test('Every write is permission-filtered and branch switch clears all loaded state and selections', () => {
    for (const text of [
        'v-if="page.canManage"',
        '!page.canManage ||',
        'form.isDirty || form.processing',
        'English (EN)',
        'Bahasa Melayu (BM)',
        'lock_version:',
        'crypto.randomUUID()',
        '/branch-context',
        'blocked.value = true',
        'form.content = {}',
        'form.branch_ids = []',
        "galleryCandidate.value = ''",
        'selected.value = null',
        'history: []',
        'linkedServices: []',
        'assets: []',
        'drafts: {}',
        'onUnmounted(stop)',
        '<template v-else>',
        'xl:grid-cols-2',
        'min-w-0',
        'flex-wrap',
        '[overflow-wrap:anywhere]',
    ]) {
        assert.ok(source.replace(/\s+/g, ' ').includes(text), text);
    }

    const controller = read(
        'app/Http/Controllers/WebsiteEditorialController.php',
    );
    assert.doesNotMatch(controller, /\bback\(/);
    assert.equal((controller.match(/redirect\(\)->route\(/g) ?? []).length, 2);
});
test('All three destinations use existing view authority and forbidden families stay absent', () => {
    const nav = read('app/Domain/Access/ClinicManagementNavigation.php');

    for (const label of ['Promotions', 'Activities', 'FAQs']) {
        assert.match(
            nav,
            new RegExp(`website.content.view.organisation'\\), '${label}'`),
        );
    }

    assert.doesNotMatch(nav, /website\.publish|website\.requests/);
});
test('Actual saved preview escapes clinical-looking markup without interpreting it and retains section and gallery order', async () => {
    const require = createRequire(import.meta.url);
    const { descriptor } = parse(preview);
    const compiled = compileScript(descriptor, {
        id: 'services-preview',
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
    const revision = {
        language: 'en',
        number: 2,
        branchIds: [1],
        content: {
            title: '<script>unsafe</script>',
            short_summary: 'Manual summary',
            description_sections: [
                { heading: 'First heading', body: '<img onerror=unsafe>' },
                { heading: 'Second heading', body: 'Second body' },
            ],
            terms: ['First bullet'],
            cover_public_id: null,
            gallery_public_ids: ['synthetic-second', 'synthetic-first'],
            sort_order: 0,
            include_on_website: false,
        },
    };
    const html = await renderToString(
        createSSRApp({
            render: () =>
                h(module.exports.default, { revision, type: 'activity' }),
        }),
    );
    assert.match(html, /&lt;script&gt;unsafe&lt;\/script&gt;/);
    assert.match(html, /&lt;img onerror=unsafe&gt;/);
    assert.doesNotMatch(html, /<script>|<img onerror/);
    assert.ok(html.indexOf('First heading') < html.indexOf('Second heading'));
    assert.ok(
        html.indexOf('synthetic-second/image') <
            html.indexOf('synthetic-first/image'),
    );
});

test('Editorial source adds no public delivery, scheduler, integration or permission', () => {
    const service = read(
        'app/Domain/Organisation/Website/WebsiteEditorialService.php',
    );
    assert.doesNotMatch(
        service,
        /Http::|dispatch\(|schedule\(|Patient::|User::query|Pricing/,
    );
    assert.match(service, /WebsiteSharedReferences::append/);
    assert.match(source, /linked_service_public_id/);
    assert.match(source, /page\.timezone/);
    assert.match(preview, /revision\.content\.linked_service_public_id/);
});
