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
const source = read('resources/js/pages/Website/LittleShield.vue');
const preview = read(
    'resources/js/components/website/LittleShieldDraftPreview.vue',
);

test('Little Shield compiles as bounded private drafts with ordered fields and no collection or delivery', () => {
    for (const [filename, text] of [
        ['LittleShield.vue', source],
        ['LittleShieldDraftPreview.vue', preview],
    ]) {
        const descriptor = parse(text).descriptor;
        const script = compileScript(descriptor, { id: filename });
        assert.deepEqual(
            compileTemplate({
                id: filename,
                filename,
                source: descriptor.template.content,
                compilerOptions: { bindingMetadata: script.bindings },
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
        'child, parent or school personal data',
        'recorded consent',
        'guarantees outcomes',
        'draft intent only',
        'No form',
        'Incomplete',
        'Move up',
        'Move down',
        'Remove',
        'Poster gallery (up to 8',
        'Saved draft preview',
        'Read-only',
        'Coverage branches',
    ]) {
        assert.ok((source + preview).replace(/\s+/g, ' ').includes(text), text);
    }
});
test('Little Shield writes are permission filtered and branch switch clears all loaded drafts', () => {
    for (const text of [
        'v-if="page.canManage"',
        '!page.canManage ||',
        'form.isDirty || form.processing',
        'lock_version:',
        'crypto.randomUUID()',
        '/branch-context',
        'blocked.value = true',
        'form.content = {}',
        'form.branch_ids = []',
        'form.item_public_id = null',
        "galleryCandidate.value = ''",
        'selected.value = null',
        'history: []',
        'branches: []',
        'assets: []',
        'drafts: {}',
        'onUnmounted(stop)',
        'Branch changed.',
        'Reload saved drafts',
        'preserveState: false',
        'xl:grid-cols-2',
        'min-w-0',
        'flex-wrap',
        '[overflow-wrap:anywhere]',
    ]) {
        assert.ok(source.replace(/\s+/g, ' ').includes(text), text);
    }

    assert.match(
        read('app/Http/Controllers/WebsiteLittleShieldController.php'),
        /redirect\(\)->route\('website.little-shield.index'\)/,
    );
});
test('Little Shield destination and route use existing permissions with no public or new authority', () => {
    assert.match(
        read('app/Domain/Access/ClinicManagementNavigation.php'),
        /website.content.view.organisation'\), 'Little Shield'/,
    );
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /website\/little-shield.*WebsiteLittleShieldController.*permission:website.content.view.organisation/,
    );
    assert.match(
        routes,
        /website\/little-shield.*WebsiteLittleShieldController.*permission:website.content.manage.organisation/,
    );
    const service = read(
        'app/Domain/Organisation/Website/WebsiteLittleShieldService.php',
    );
    assert.match(service, /WebsiteSharedReferences::append/);
    assert.doesNotMatch(
        service,
        /Http::|dispatch\(|schedule\(|Patient::|User::query|Pricing/,
    );
    assert.doesNotMatch(
        read('app/Domain/Organisation/Website/WebsitePermissions.php'),
        /little_shield|little-shield/,
    );
});
test('Actual Little Shield saved preview escapes text and retains steps and poster order', async () => {
    const require = createRequire(import.meta.url);
    const compiled = compileScript(parse(preview).descriptor, {
        id: 'little-preview',
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
        language: 'bm',
        number: 2,
        branchIds: [1, 3],
        content: {
            title: '<script>unsafe</script>',
            tagline: 'Synthetic',
            introduction: '<img onerror=unsafe>',
            overview_paragraphs: ['Second paragraph', 'First paragraph'],
            programme_pillars: [
                { title: 'Pillar', description: 'Description' },
            ],
            programme_flow_steps: [
                { title: 'Second step', description: 'Step two' },
                { title: 'First step', description: 'Step one' },
            ],
            medical_safeguards: ['Synthetic safeguard'],
            partnership_introduction: 'Partners',
            partner_types: [{ title: 'Partner', description: 'Description' }],
            how_to_take_part: 'Text only',
            cover_public_id: null,
            gallery_public_ids: ['synthetic-second', 'synthetic-first'],
            include_on_website: false,
        },
    };
    const html = await renderToString(
        createSSRApp({ render: () => h(module.exports.default, { revision }) }),
    );
    assert.match(html, /&lt;script&gt;unsafe&lt;\/script&gt;/);
    assert.match(html, /&lt;img onerror=unsafe&gt;/);
    assert.doesNotMatch(html, /<script>|<img onerror/);
    assert.ok(html.indexOf('Second step') < html.indexOf('First step'));
    assert.ok(
        html.indexOf('Second paragraph') < html.indexOf('First paragraph'),
    );
    assert.ok(
        html.indexOf('synthetic-second/image') <
            html.indexOf('synthetic-first/image'),
    );
    assert.match(html, /1, 3/);
});

test('Little Shield active language is visibly selected and Title is a bounded single-line input', () => {
    for (const language of ['en', 'bm']) {
        assert.ok(
            source.includes(
                `:variant="language === '${language}' ? 'default' : 'outline'"`,
            ),
        );
        assert.ok(
            source.includes(`:aria-pressed="language === '${language}'"`),
        );
    }

    assert.match(
        source,
        /<input\s+v-if="key === 'title'"\s+v-model="form.content\[key\]"\s+type="text"\s+:maxlength="limit"\s+:class="inputClass"/,
    );
    assert.match(
        source,
        /<textarea\s+v-else\s+v-model="form.content\[key\]"\s+:maxlength="limit"/,
    );
});
